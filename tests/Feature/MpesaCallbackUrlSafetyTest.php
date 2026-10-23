<?php

namespace Tests\Feature;

use Tests\TestCase;

class MpesaCallbackUrlSafetyTest extends TestCase
{
    public function test_callback_url_path_does_not_contain_provider_rejected_substrings(): void
    {
        $path = parse_url((string) config('mpesa.callback_url'), PHP_URL_PATH);

        $this->assertIsString($path);

        foreach (['mpesa', 'safaricom', 'exe', 'exec', 'cmd', 'sql', 'query'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase(
                $word,
                $path,
                "Callback URL path contains '{$word}' which Safaricom rejects.",
            );
        }
    }
}
