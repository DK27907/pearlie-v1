<?php

namespace App\Http\Controllers;

use App\Exceptions\MissingProviderCredentialsException;
use App\Jobs\ProcessWhatsAppInboundMessage;
use App\Models\Hospital;
use App\Services\ProviderCredentialResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly ProviderCredentialResolver $credentialResolver,
    ) {}

    public function verify(Request $request): Response
    {
        return $this->verificationResponse(
            $request,
            [(string) config('whatsapp.verify_token', '')],
        );
    }

    public function verifyTenant(Request $request): Response
    {
        try {
            $token = (string) $this->whatsappCredential('whatsapp_verify_token', 'verify_token');
        } catch (MissingProviderCredentialsException $exception) {
            Log::error('WhatsApp webhook verification credentials are missing.', [
                'exception' => $exception,
            ]);

            return response($exception->getMessage(), 503);
        }

        return $this->verificationResponse($request, [$token]);
    }

    /**
     * @param  array<int, string>  $expectedTokens
     */
    private function verificationResponse(Request $request, array $expectedTokens): Response
    {
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        $matched = false;
        foreach ($expectedTokens as $expectedToken) {
            if ($expectedToken !== '' && hash_equals($expectedToken, $token)) {
                $matched = true;
                break;
            }
        }

        if ($mode !== 'subscribe' || ! $matched) {
            return response('Forbidden', 403)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function receive(Request $request): Response
    {
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

        $hospital = hospital();
        if (! $hospital instanceof Hospital) {
            throw new RuntimeException('A hospital context is required to queue an inbound WhatsApp message.');
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    if (empty($message['from'])) {
                        continue;
                    }

                    $type = $message['type'] ?? null;
                    $incomingMessage = match ($type) {
                        'text' => data_get($message, 'text.body'),
                        'interactive' => data_get($message, 'interactive.list_reply.id')
                            ?? data_get($message, 'interactive.button_reply.id')
                            ?? data_get($message, 'interactive.button_reply.title'),
                        'button' => data_get($message, 'button.payload')
                            ?? data_get($message, 'button.text'),
                        default => null,
                    };
                    if (! is_string($incomingMessage) || $incomingMessage === '') {
                        continue;
                    }

                    ProcessWhatsAppInboundMessage::dispatch(
                        (int) $hospital->id,
                        (string) $message['from'],
                        $incomingMessage,
                        isset($message['id']) ? (string) $message['id'] : null,
                        isset($message['timestamp']) ? (string) $message['timestamp'] : null,
                    );
                }
            }
        }

        return response('EVENT_RECEIVED', 200);
    }

    public function handle(Request $request): Response
    {
        return $this->receive($request);
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
