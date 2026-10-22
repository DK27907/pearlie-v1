<?php

namespace App\Services;

final readonly class SmsResult
{
    public function __construct(
        public bool $success,
        public string $status,
        public ?string $providerMessageId = null,
        public ?string $message = null,
    ) {}
}
