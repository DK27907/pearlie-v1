<?php

namespace Tests\Feature\Admin;

use App\Models\Escalation;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EscalationAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_assign_an_active_doctor_from_their_hospital(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dr. Amina Njeri',
            'is_doctor' => true,
            'role' => 'doctor',
            'is_active' => true,
        ]);
        $escalation = Escalation::query()->create([
            'session_id' => 'assignable-escalation',
            'user_message' => 'Please arrange a follow-up.',
            'status' => Escalation::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.escalations.show', $escalation))
            ->assertOk()
            ->assertSee('Assign to doctor')
            ->assertSee('Dr. Amina Njeri');
        $this->actingAs($admin)
            ->post(route('admin.escalations.assign', $escalation), ['doctor_id' => $doctor->id])
            ->assertRedirect(route('admin.escalations.show', $escalation))
            ->assertSessionHas('status', 'Escalation assigned.');

        $this->assertDatabaseHas('escalations', [
            'id' => $escalation->id,
            'hospital_id' => $hospital->id,
            'assigned_worker_id' => $doctor->id,
        ]);
    }

    public function test_admin_cannot_assign_a_doctor_from_another_hospital(): void
    {
        $hospitalA = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        $hospitalB = Hospital::factory()->create();
        app()->instance('currentHospital', $hospitalB);
        $otherDoctor = User::factory()->create([
            'hospital_id' => $hospitalB->id,
            'is_doctor' => true,
            'role' => 'doctor',
            'is_active' => true,
        ]);
        app()->instance('currentHospital', $hospitalA);
        $admin = User::factory()->create([
            'hospital_id' => $hospitalA->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $escalation = Escalation::query()->create([
            'session_id' => 'cross-hospital-doctor-escalation',
            'user_message' => 'Please arrange a follow-up.',
            'status' => Escalation::STATUS_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.escalations.assign', $escalation), ['doctor_id' => $otherDoctor->id])
            ->assertNotFound();

        $this->assertDatabaseHas('escalations', [
            'id' => $escalation->id,
            'hospital_id' => $hospitalA->id,
            'assigned_worker_id' => null,
        ]);
    }

    public function test_admin_cannot_assign_an_escalation_from_another_hospital(): void
    {
        $hospitalA = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        $hospitalB = Hospital::factory()->create();
        app()->instance('currentHospital', $hospitalB);
        $otherEscalation = Escalation::query()->create([
            'session_id' => 'foreign-escalation',
            'user_message' => 'Private patient request.',
            'status' => Escalation::STATUS_PENDING,
        ]);
        app()->instance('currentHospital', $hospitalA);
        $admin = User::factory()->create([
            'hospital_id' => $hospitalA->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $doctor = User::factory()->create([
            'hospital_id' => $hospitalA->id,
            'is_doctor' => true,
            'role' => 'doctor',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.escalations.assign', $otherEscalation->id), ['doctor_id' => $doctor->id])
            ->assertNotFound();

        $this->assertDatabaseHas('escalations', [
            'id' => $otherEscalation->id,
            'hospital_id' => $hospitalB->id,
            'assigned_worker_id' => null,
        ]);
    }
}
