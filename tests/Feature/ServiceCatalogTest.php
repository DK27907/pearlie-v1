<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_admin_can_create_service_for_own_hospital(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-create');

        $this->actingAs($admin)
            ->post(route('admin.services.store'), $this->validServiceData())
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', [
            'hospital_id' => $hospital->id,
            'name' => 'General Consultation',
            'price' => 1500,
        ]);
    }

    public function test_admin_cannot_create_service_for_another_hospital(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-create-owner');
        $otherHospital = $this->createHospital('catalog-create-foreign');

        $this->actingAs($admin)
            ->post(route('admin.services.store'), $this->validServiceData([
                'hospital_id' => $otherHospital->id,
                'name' => 'Tenant-Owned Consultation',
            ]))
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', [
            'hospital_id' => $hospital->id,
            'name' => 'Tenant-Owned Consultation',
        ]);
        $this->assertDatabaseMissing('services', [
            'hospital_id' => $otherHospital->id,
            'name' => 'Tenant-Owned Consultation',
        ]);
    }

    public function test_admin_can_list_own_services_only(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-list-owner');
        $otherHospital = $this->createHospital('catalog-list-foreign');
        $ownerService = $this->createService($hospital, ['name' => 'Owner Consultation']);
        $this->createService($otherHospital, ['name' => 'Foreign Consultation']);

        $this->actingAs($admin)
            ->get(route('admin.services.index'))
            ->assertOk()
            ->assertSee('Owner Consultation')
            ->assertSee(route('admin.services.destroy', $ownerService))
            ->assertSee('name="_method" value="DELETE"', false)
            ->assertDontSee('Foreign Consultation');
    }

    public function test_admin_can_edit_own_service(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-edit');
        $service = $this->createService($hospital);

        $this->actingAs($admin)
            ->get(route('admin.services.edit', $service))
            ->assertOk()
            ->assertSee($service->name);

        $this->actingAs($admin)
            ->put(route('admin.services.update', $service), $this->validServiceData([
                'name' => 'Updated Consultation',
            ]))
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'hospital_id' => $hospital->id,
            'name' => 'Updated Consultation',
        ]);
    }

    public function test_admin_cannot_edit_another_hospitals_service(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-edit-owner');
        $otherHospital = $this->createHospital('catalog-edit-foreign');
        $service = $this->createService($otherHospital);

        $this->actingAs($admin)
            ->get(route('admin.services.edit', $service->id))
            ->assertNotFound();

        $this->actingAs($admin)
            ->put(route('admin.services.update', $service->id), $this->validServiceData([
                'name' => 'Unauthorized Update',
            ]))
            ->assertNotFound();

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'hospital_id' => $otherHospital->id,
            'name' => $service->name,
        ]);
    }

    public function test_admin_can_delete_own_service(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-delete');
        $service = $this->createService($hospital);

        $this->actingAs($admin)
            ->delete(route('admin.services.destroy', $service))
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'hospital_id' => $hospital->id,
            'is_active' => false,
        ]);
    }

    public function test_admin_cannot_delete_another_hospitals_service(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-delete-owner');
        $otherHospital = $this->createHospital('catalog-delete-foreign');
        $service = $this->createService($otherHospital);

        $this->actingAs($admin)
            ->delete(route('admin.services.destroy', $service->id))
            ->assertNotFound();

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'hospital_id' => $otherHospital->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_toggle_service_active(): void
    {
        [$hospital, $admin] = $this->createHospitalAdmin('catalog-toggle');
        $service = $this->createService($hospital, ['is_active' => false]);

        $this->actingAs($admin)
            ->patch(route('admin.services.toggle-active', $service))
            ->assertRedirect(route('admin.services.index'));

        $this->assertDatabaseHas('services', [
            'id' => $service->id,
            'is_active' => true,
        ]);
    }

    public function test_validation_rejects_missing_name(): void
    {
        [, $admin] = $this->createHospitalAdmin('catalog-validation-name');
        $data = $this->validServiceData();
        unset($data['name']);

        $this->actingAs($admin)
            ->postJson(route('admin.services.store'), $data)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_validation_rejects_negative_price(): void
    {
        [, $admin] = $this->createHospitalAdmin('catalog-validation-price');

        $this->actingAs($admin)
            ->postJson(route('admin.services.store'), $this->validServiceData([
                'price' => -1,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    public function test_validation_rejects_out_of_range_duration(): void
    {
        [, $admin] = $this->createHospitalAdmin('catalog-validation-duration');

        $this->actingAs($admin)
            ->postJson(route('admin.services.store'), $this->validServiceData([
                'duration_minutes' => 1000,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('duration_minutes');
    }

    /**
     * @return array{Hospital, User}
     */
    private function createHospitalAdmin(string $slug): array
    {
        $hospital = $this->createHospital($slug);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        app()->forgetInstance('currentHospital');

        return [$hospital, $admin];
    }

    private function createHospital(string $slug): Hospital
    {
        return Hospital::factory()->create([
            'slug' => $slug,
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createService(Hospital $hospital, array $attributes = []): Service
    {
        app()->instance('currentHospital', $hospital);
        $service = Service::factory()->create($attributes);
        app()->forgetInstance('currentHospital');

        return $service;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validServiceData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'General Consultation',
            'description' => 'A standard consultation.',
            'price' => 1500,
            'duration_minutes' => 30,
            'category' => 'General',
            'requires_specialty' => null,
            'is_active' => true,
        ], $overrides);
    }
}
