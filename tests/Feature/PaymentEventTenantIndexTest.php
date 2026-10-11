<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentEventTenantIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_events_have_a_hospital_id_index(): void
    {
        $indexNames = collect(DB::select('PRAGMA index_list(payment_events)'))
            ->pluck('name')
            ->all();

        $this->assertContains('payment_events_hospital_id_index', $indexNames);
    }
}
