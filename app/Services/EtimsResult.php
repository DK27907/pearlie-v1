<?php

namespace App\Services;

final readonly class EtimsResult
{
    public function __construct(
        public string $status,
        public ?string $invoiceNumber,
        public ?string $qrData,
        public string $message,
    ) {}

    public static function notConfigured(): self
    {
        return new self('not_applicable', null, null, 'eTIMS is not enabled for this hospital.');
    }

    public static function pending(): self
    {
        return new self('pending', null, null, 'eTIMS is enabled but submission is not implemented.');
    }
}
