<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppBookingService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('services.whatsapp.verify_token');
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

    public function receive(Request $request, WhatsAppBookingService $booking): Response
    {
        $secret = (string) config('services.whatsapp.app_secret');
        if ($secret !== '') {
            $signature = (string) $request->header('X-Hub-Signature-256');
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
            if ($signature === '' || ! hash_equals($expected, $signature)) {
                return response('Invalid signature', 401);
            }
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
                        $result = $booking->handle((string) $message['from'], (string) $message['text']['body']);
                        app(\App\Services\NotificationService::class)->sendWhatsApp(
                            (string) $message['from'],
                            (string) ($result['response'] ?? ''),
                        );
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
}
