<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class ExternalProviderLogger
{
    public function record(
        string $provider,
        string $operation,
        string $status,
        ?int $httpStatus = null,
        ?string $failureCode = null,
        ?Throwable $exception = null,
    ): void {
        $level = match ($status) {
            'failed' => 'error',
            'not_configured' => 'warning',
            default => 'info',
        };

        Log::log($level, 'external_provider_call', [
            'provider' => $this->safeLabel($provider),
            'operation' => $this->safeLabel($operation),
            'status' => $this->safeLabel($status),
            'http_status' => $httpStatus,
            'failure_code' => $failureCode === null ? null : $this->safeLabel($failureCode),
            'hospital_id' => hospital()?->id,
            'exception_class' => $exception === null ? null : $exception::class,
        ]);
    }

    private function safeLabel(string $value): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_.-]/', '_', $value) ?? 'unknown', 0, 64);
    }
}
