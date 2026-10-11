<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class PolicyResult
{
    public function __construct(
        public readonly bool $canCancel,
        public readonly bool $canReschedule,
        public readonly bool $isLateCancellation,
        public readonly CarbonImmutable $cancellationCutoffAt,
        public readonly CarbonImmutable $reschedulingCutoffAt,
        public readonly float $lateCancellationFee,
        public readonly bool $depositsRefundable,
    ) {}
}
