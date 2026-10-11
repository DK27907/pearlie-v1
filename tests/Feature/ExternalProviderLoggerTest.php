<?php

namespace Tests\Feature;

use App\Services\ExternalProviderLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExternalProviderLoggerTest extends TestCase
{
    public function test_external_call_logs_are_structured_and_never_include_exception_messages(): void
    {
        Log::spy();

        app(ExternalProviderLogger::class)->record(
            'meta_whatsapp',
            'message_send',
            'failed',
            503,
            'http_503',
            new RuntimeException('Bearer secret-token sent to 254712345678: private message'),
        );

        Log::shouldHaveReceived('log')
            ->once()
            ->with('error', 'external_provider_call', Mockery::on(function (array $context): bool {
                $serialized = json_encode($context, JSON_THROW_ON_ERROR);

                return $context['provider'] === 'meta_whatsapp'
                    && $context['operation'] === 'message_send'
                    && $context['status'] === 'failed'
                    && $context['http_status'] === 503
                    && $context['exception_class'] === RuntimeException::class
                    && ! str_contains($serialized, 'secret-token')
                    && ! str_contains($serialized, '254712345678')
                    && ! str_contains($serialized, 'private message');
            }));
    }
}
