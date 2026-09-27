<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_is_unavailable_when_signature_verification_is_not_configured(): void
    {
        config(['services.whatsapp.app_secret' => null]);

        $this->postJson('/api/webhooks/whatsapp', [
            'object' => 'whatsapp_business_account',
        ])->assertStatus(503);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        config(['services.whatsapp.app_secret' => 'test-app-secret']);

        $this->postJson('/api/webhooks/whatsapp', [
            'object' => 'whatsapp_business_account',
        ], [
            'X-Hub-Signature-256' => 'sha256=invalid',
        ])->assertStatus(401);
    }

    public function test_webhook_accepts_a_valid_signature(): void
    {
        config(['services.whatsapp.app_secret' => 'test-app-secret']);
        $payload = ['object' => 'whatsapp_business_account'];
        $content = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $content, 'test-app-secret');

        $this->call(
            'POST',
            '/api/webhooks/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $content,
        )->assertOk()->assertContent('EVENT_RECEIVED');
    }
}
