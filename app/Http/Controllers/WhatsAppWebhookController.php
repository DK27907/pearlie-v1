<?php

namespace App\Http\Controllers;

use App\Exceptions\MissingProviderCredentialsException;
use App\Services\BookingChatService;
use App\Services\ProviderCredentialResolver;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly ProviderCredentialResolver $credentialResolver,
    ) {}

    public function verify(Request $request): Response
    {
        try {
            $token = (string) $this->whatsappCredential('whatsapp_verify_token', 'verify_token');
        } catch (MissingProviderCredentialsException $exception) {
            Log::error('WhatsApp webhook verification credentials are missing.', [
                'exception' => $exception,
            ]);

            return response($exception->getMessage(), 503);
        }

        $providedToken = (string) ($request->query('hub.verify_token') ?: $request->query('hub_verify_token'));
        $mode = (string) ($request->query('hub.mode') ?: $request->query('hub_mode'));
        if ($token === '' || ! hash_equals($token, $providedToken)) {
            return response('Forbidden', 403);
        }

        if ($mode !== 'subscribe') {
            return response('Bad Request', 400);
        }

        return response((string) $request->query('hub_challenge'), 200);
    }

    public function receive(
        Request $request,
        BookingChatService $booking,
        WhatsAppService $whatsApp,
    ): Response {
        try {
            $secret = (string) $this->whatsappCredential('whatsapp_app_secret', 'app_secret');
        } catch (MissingProviderCredentialsException $exception) {
            Log::error('WhatsApp webhook signature credentials are missing.', [
                'exception' => $exception,
            ]);

            return response($exception->getMessage(), 503);
        }

        if ($secret === '') {
            Log::error('WhatsApp webhook rejected because signature verification is not configured.');

            return response('Webhook signature verification is not configured.', 503);
        }

        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($signature === '' || ! hash_equals($expected, $signature)) {
            return response('Invalid signature', 401);
        }

        try {
            $this->whatsappCredential('whatsapp_phone_number_id', 'phone_number_id');
            $this->whatsappCredential('whatsapp_access_token', 'access_token');
        } catch (MissingProviderCredentialsException $exception) {
            Log::error('WhatsApp outbound credentials are missing for the inbound webhook.', [
                'exception' => $exception,
            ]);

            return response($exception->getMessage(), 503);
        }

        $payload = $request->json()->all();
        if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return response('Ignored', 200);
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    if (($message['type'] ?? null) !== 'text' || empty($message['from']) || ! isset($message['text']['body'])) {
                        continue;
                    }

                    try {
                        $result = $booking->handle(
                            (string) $message['from'],
                            (string) $message['text']['body'],
                            'whatsapp',
                        );
                        $whatsApp->sendMessage(
                            (string) $message['from'],
                            $result->message,
                        );
                    } catch (MissingProviderCredentialsException $exception) {
                        Log::error('WhatsApp inbound message could not be handled because provider credentials are missing.', [
                            'from' => $message['from'],
                            'exception' => $exception,
                        ]);

                        return response($exception->getMessage(), 503);
                    } catch (\Throwable $e) {
                        Log::error('WhatsApp inbound message failed.', [
                            'from' => $message['from'],
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    public function handle(
        Request $request,
        BookingChatService $booking,
        WhatsAppService $whatsApp,
    ): Response {
        return $this->receive($request, $booking, $whatsApp);
    }

    private function whatsappCredential(string $tenantAttribute, string $credentialKey): ?string
    {
        $tenant = hospital();
        if (! $tenant) {
            $configuredValue = config('whatsapp.'.$credentialKey);

            return $configuredValue === null ? null : (string) $configuredValue;
        }

        $config = $this->credentialResolver->resolve(
            $tenant,
            'WhatsApp',
            (array) config('whatsapp'),
            [
                'phone_number_id' => 'whatsapp_phone_number_id',
                'access_token' => 'whatsapp_access_token',
                'verify_token' => 'whatsapp_verify_token',
                'app_secret' => 'whatsapp_app_secret',
                'api_version' => 'whatsapp_api_version',
            ],
            [$credentialKey],
        );
        $value = $config[$credentialKey] ?? null;

        return $value === null ? null : (string) $value;
    }
}
