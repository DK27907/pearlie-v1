<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\WebhookUrlGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DeliverWebhookDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 120, 300];

    public int $timeout = 20;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(WebhookUrlGuard $urlGuard): void
    {
        Cache::lock('webhook-delivery-'.$this->deliveryId, 45)
            ->block(5, function () use ($urlGuard): void {
                $delivery = WebhookDelivery::query()->findOrFail($this->deliveryId);
                if ($delivery->status !== WebhookDelivery::STATUS_PENDING) {
                    return;
                }

                $subscription = WebhookSubscription::withoutGlobalScopes()
                    ->where('hospital_id', $delivery->hospital_id)
                    ->whereKey($delivery->webhook_subscription_id)
                    ->first();
                if (! $subscription?->is_active
                    || ! in_array($delivery->event, $subscription->events ?? [], true)
                ) {
                    $delivery->forceFill([
                        'status' => WebhookDelivery::STATUS_CANCELLED,
                        'next_attempt_at' => null,
                    ])->save();

                    return;
                }

                $attempt = min($delivery->attempts + 1, $this->tries);
                $delivery->forceFill([
                    'attempts' => $attempt,
                    'next_attempt_at' => now()->addSeconds($this->backoff[min($attempt - 1, count($this->backoff) - 1)]),
                ])->save();

                try {
                    $addresses = $urlGuard->resolvePublicAddresses($subscription->url);
                } catch (\InvalidArgumentException $exception) {
                    $delivery->forceFill([
                        'status' => WebhookDelivery::STATUS_FAILED,
                        'last_error' => $exception::class,
                        'next_attempt_at' => null,
                    ])->save();

                    return;
                }

                if (! defined('CURLOPT_RESOLVE')) {
                    throw new RuntimeException('The cURL extension is required for pinned webhook delivery.');
                }

                $body = json_encode(
                    $delivery->payload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                );

                try {
                    $response = Http::withOptions([
                        'allow_redirects' => false,
                        'curl' => [
                            constant('CURLOPT_RESOLVE') => $urlGuard->curlResolveEntries(
                                $subscription->url,
                                $addresses,
                            ),
                        ],
                    ])
                        ->withHeaders([
                            'X-Pearlie-Event' => $delivery->event,
                            'X-Pearlie-Delivery' => $delivery->delivery_id,
                            'X-Pearlie-Signature' => 'sha256='.hash_hmac('sha256', $body, $subscription->secret),
                        ])
                        ->acceptJson()
                        ->connectTimeout(3)
                        ->timeout(10)
                        ->withBody($body, 'application/json')
                        ->post($subscription->url);

                    if ($response->successful()) {
                        $delivery->forceFill([
                            'status' => WebhookDelivery::STATUS_DELIVERED,
                            'http_status' => $response->status(),
                            'last_error' => null,
                            'next_attempt_at' => null,
                            'delivered_at' => now(),
                        ])->save();

                        return;
                    }

                    if ($response->serverError() || $response->status() === 429) {
                        $delivery->forceFill(['http_status' => $response->status()])->save();

                        throw new RuntimeException('Webhook endpoint returned a retryable HTTP status.');
                    }

                    $delivery->forceFill([
                        'status' => WebhookDelivery::STATUS_FAILED,
                        'http_status' => $response->status(),
                        'last_error' => 'Webhook endpoint returned a non-retryable HTTP status.',
                        'next_attempt_at' => null,
                    ])->save();
                } catch (Throwable $exception) {
                    $delivery->forceFill([
                        'status' => WebhookDelivery::STATUS_PENDING,
                        'last_error' => $exception::class,
                    ])->save();

                    throw $exception;
                }
            });
    }

    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', WebhookDelivery::STATUS_PENDING)
            ->update([
                'status' => WebhookDelivery::STATUS_FAILED,
                'next_attempt_at' => null,
                'last_error' => $exception instanceof Throwable
                    ? $exception::class
                    : 'Webhook delivery retries exhausted.',
                'updated_at' => now(),
            ]);

        Log::error('Webhook delivery exhausted its retry budget.', [
            'delivery_id' => $this->deliveryId,
            'exception_class' => $exception instanceof Throwable ? $exception::class : null,
        ]);
    }
}
