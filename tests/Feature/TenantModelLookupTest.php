<?php

namespace Tests\Feature;

use App\Models\Escalation;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantModelLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_cross_tenant_and_missing_ids_have_the_same_not_found_behavior(): void
    {
        [$hospital, $admin] = $this->createTenant('lookup-owner');
        [$otherHospital] = $this->createTenant('lookup-other');
        $otherUser = User::factory()->create([
            'hospital_id' => $otherHospital->id,
            'role' => 'patient',
        ]);
        $otherDoctor = User::factory()->create([
            'hospital_id' => $otherHospital->id,
            'role' => 'doctor',
            'is_doctor' => true,
        ]);
        app()->instance('currentHospital', $otherHospital);
        $otherEscalation = Escalation::withoutGlobalScopes()->create([
            'hospital_id' => $otherHospital->id,
            'session_id' => 'private-other-tenant-session',
            'user_message' => 'Private escalation.',
            'status' => Escalation::STATUS_PENDING,
        ]);
        app()->instance('currentHospital', $hospital);
        Role::findOrCreate('patient', 'web');

        $this->actingAs($admin)
            ->post(route('admin.roles.assign'), ['user_id' => $otherUser->id, 'role' => 'patient'])
            ->assertNotFound();
        $this->post(route('admin.roles.assign'), ['user_id' => 999999, 'role' => 'patient'])
            ->assertNotFound();

        $this->post(route('admin.escalations.assign', $otherEscalation->id), ['doctor_id' => $otherDoctor->id])
            ->assertNotFound();
        $this->post(route('admin.escalations.bulk'), [
            'action' => 'delete',
            'ids' => [$otherEscalation->id],
        ])->assertNotFound();

        $this->assertDatabaseHas('escalations', ['id' => $otherEscalation->id]);
    }

    /**
     * @return array{Hospital, User}
     */
    private function createTenant(string $slug): array
    {
        $hospital = Hospital::factory()->create([
            'slug' => $slug,
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
        ]);

        return [$hospital, $admin];
    }
}
