<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookVerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_verify_returns_challenge_as_plain_text(): void
    {
        config(['whatsapp.verify_token' => 'medidesk-demo-2026']);

        $response = $this->get('/api/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'medidesk-demo-2026',
            'hub.challenge' => 'test123',
        ]));

        $response->assertOk();
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('test123', $response->getContent());
    }

    public function test_webhook_verify_rejects_wrong_token(): void
    {
        config(['whatsapp.verify_token' => 'medidesk-demo-2026']);

        $response = $this->get('/api/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'wrong-token',
            'hub.challenge' => 'test123',
        ]));

        $response->assertForbidden();
    }

    public function test_webhook_verify_does_not_require_authentication(): void
    {
        config(['whatsapp.verify_token' => 'medidesk-demo-2026']);
        $this->assertGuest();

        $response = $this->get('/api/whatsapp/webhook?'.http_build_query([
            'hub.mode' => 'subscribe',
            'hub.verify_token' => 'medidesk-demo-2026',
            'hub.challenge' => 'ping',
        ]));

        $response->assertOk();
        $this->assertSame('ping', $response->getContent());
    }

    public function test_webhook_verify_handles_dot_notation_query(): void
    {
        config(['whatsapp.verify_token' => 'medidesk-demo-2026']);

        $response = $this->get('/api/whatsapp/webhook?hub.mode=subscribe&hub.verify_token=medidesk-demo-2026&hub.challenge=dot-test');

        $response->assertOk();
        $this->assertSame('dot-test', $response->getContent());
    }
}
