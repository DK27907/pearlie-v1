<?php

namespace App\Services;

use App\Models\MpesaCallbackInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MpesaCallbackInboxService
{
    private const MAX_ATTEMPTS = 8;

    public function __construct(private readonly MpesaService $mpesa) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function receive(array $payload, ?string $hospitalSlug = null): MpesaCallbackInbox
    {
        $checkoutRequestId = data_get($payload, 'Body.stkCallback.CheckoutRequestID');
        $checkoutRequestId = is_string($checkoutRequestId)
            && mb_strlen($checkoutRequestId) <= 128
            ? $checkoutRequestId
            : null;
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $inbox = MpesaCallbackInbox::query()->firstOrCreate(
            [
                'checkout_request_id' => $checkoutRequestId,
                'payload_hash' => hash('sha256', $encodedPayload),
            ],
            [
                'payload' => $payload,
                'hospital_slug' => $hospitalSlug,
                'status' => MpesaCallbackInbox::STATUS_PENDING,
                'available_at' => now(),
            ],
        );

        if ($inbox->status !== MpesaCallbackInbox::STATUS_PROCESSED
            && $hospitalSlug !== null
            && $inbox->hospital_slug !== $hospitalSlug
        ) {
            MpesaCallbackInbox::query()
                ->whereKey($inbox->id)
                ->whereIn('status', [
                    MpesaCallbackInbox::STATUS_PENDING,
                    MpesaCallbackInbox::STATUS_RETRY,
                ])
                ->update(['hospital_slug' => $hospitalSlug, 'updated_at' => now()]);
            $inbox->refresh();
        }

        return $inbox;
    }

    public function process(MpesaCallbackInbox $inbox): bool
    {
        $claimed = MpesaCallbackInbox::query()
            ->whereKey($inbox->getKey())
            ->whereIn('status', [
                MpesaCallbackInbox::STATUS_PENDING,
                MpesaCallbackInbox::STATUS_RETRY,
            ])
            ->where('available_at', '<=', now())
            ->update([
                'status' => MpesaCallbackInbox::STATUS_PROCESSING,
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $inbox->refresh();

        try {
            $payment = $this->mpesa->handleCallback($inbox->payload, $inbox->hospital_slug);
        } catch (Throwable $exception) {
            Log::error('M-Pesa callback inbox processing threw an exception.', [
                'inbox_id' => $inbox->id,
                'checkout_request_id' => $inbox->checkout_request_id,
                'exception' => $exception,
            ]);

            $this->scheduleRetry($inbox, 'Callback processing raised an exception.');

            return false;
        }

        if ($payment === null) {
            $this->scheduleRetry($inbox, 'Callback verification or payment resolution was inconclusive.');

            return false;
        }

        $inbox->forceFill([
            'status' => MpesaCallbackInbox::STATUS_PROCESSED,
            'processed_at' => now(),
            'processing_started_at' => null,
            'last_error' => null,
        ])->save();

        return true;
    }

    public function processDue(int $limit = 100): int
    {
        $now = now();
        MpesaCallbackInbox::query()
            ->where('status', MpesaCallbackInbox::STATUS_PROCESSING)
            ->where('processing_started_at', '<=', $now->copy()->subMinutes(5))
            ->update([
                'status' => MpesaCallbackInbox::STATUS_RETRY,
                'available_at' => $now,
                'processing_started_at' => null,
                'updated_at' => $now,
            ]);

        $inboxIds = MpesaCallbackInbox::query()
            ->due()
            ->orderBy('id')
            ->limit(max(1, min($limit, 500)))
            ->pluck('id');

        $processed = 0;
        foreach ($inboxIds as $inboxId) {
            $inbox = MpesaCallbackInbox::query()->find($inboxId);
            if ($inbox && $this->process($inbox)) {
                $processed++;
            }
        }

        return $processed;
    }

    private function scheduleRetry(MpesaCallbackInbox $inbox, string $reason): void
    {
        $attempts = (int) $inbox->attempts;
        $delayMinutes = [1, 2, 5, 10, 20, 40, 60][$attempts - 1] ?? 60;
        $exhausted = $attempts >= self::MAX_ATTEMPTS;

        $inbox->forceFill([
            'status' => $exhausted
                ? MpesaCallbackInbox::STATUS_FAILED
                : MpesaCallbackInbox::STATUS_RETRY,
            'available_at' => now()->addMinutes($delayMinutes),
            'processing_started_at' => null,
            'last_error' => $reason,
        ])->save();

        if ($exhausted) {
            Log::error('M-Pesa callback inbox exhausted its retry limit.', [
                'inbox_id' => $inbox->id,
                'checkout_request_id' => $inbox->checkout_request_id,
                'attempts' => $attempts,
            ]);
        }
    }
}
