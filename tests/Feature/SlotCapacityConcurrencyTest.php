<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\SlotCapacity;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SlotCapacityConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // SQLite ignores FOR UPDATE, so this verifies sequential reservations, not concurrent row-lock behavior.
    public function test_capacity_invariant_rejects_a_second_sequential_reservation(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'is_doctor' => true,
            'is_active' => true,
            'role' => 'doctor',
        ]);
        $availability = app(DoctorAvailabilityService::class);
        $slotDate = CarbonImmutable::parse('2026-11-01');

        $firstReservation = DB::transaction(fn (): ?SlotCapacity => $availability->reserveSlotCapacity(
            $hospital,
            $doctor->id,
            $slotDate,
            '09:00',
            1,
        ));
        $secondReservation = DB::transaction(fn (): ?SlotCapacity => $availability->reserveSlotCapacity(
            $hospital,
            $doctor->id,
            $slotDate,
            '09:00',
            1,
        ));

        $this->assertInstanceOf(SlotCapacity::class, $firstReservation);
        $this->assertNull($secondReservation);
        $this->assertDatabaseHas('slot_capacity', [
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'slot_date' => '2026-11-01 00:00:00',
            'slot_time' => '09:00:00',
            'capacity' => 1,
            'booked_count' => 1,
        ]);
    }
}
