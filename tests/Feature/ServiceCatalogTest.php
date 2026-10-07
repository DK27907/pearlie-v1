<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\DoctorAvailabilityService;
use App\Services\MpesaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
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

    public function test_booking_with_service_uses_service_price(): void
    {
        [$hospital, $doctor] = $this->createBookingContext('catalog-book-service', 700);
        $service = $this->createService($hospital, ['price' => 2500]);
        $this->travelTo('2026-10-02 08:00:00');
        Queue::fake();
        Http::fake([
            'https://sandbox.safaricom.co.ke/oauth/v1/generate*' => Http::response([
                'access_token' => 'test-access-token',
            ]),
            'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'MerchantRequestID' => 'merchant-service-price',
                'CheckoutRequestID' => 'checkout-service-price',
            ]),
        ]);

        $appointment = $this->bookSlot($doctor, 'service-price-session', $service);
        app(MpesaService::class)->initiateStkPush($appointment);

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'hospital_id' => $hospital->id,
            'service_id' => $service->id,
            'payment_amount' => 2500,
            'booking_fee' => 2500,
        ]);
        Http::assertSent(fn ($request): bool => str_ends_with(
            $request->url(),
            '/mpesa/stkpush/v1/processrequest',
        ) && $request['Amount'] === 2500);
        Queue::assertNothingPushed();
    }

    public function test_booking_without_service_uses_deposit_amount(): void
    {
        [$hospital, $doctor] = $this->createBookingContext('catalog-book-deposit', 725);
        $this->travelTo('2026-10-02 08:00:00');
        Queue::fake();

        $appointment = $this->bookSlot($doctor, 'deposit-price-session');

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'hospital_id' => $hospital->id,
            'service_id' => null,
            'payment_amount' => null,
            'booking_fee' => 725,
        ]);
        Queue::assertNothingPushed();
    }

    public function test_booking_rejects_inactive_service(): void
    {
        [$hospital, $doctor] = $this->createBookingContext('catalog-book-inactive');
        $service = $this->createService($hospital, ['is_active' => false]);
        $this->travelTo('2026-10-02 08:00:00');
        Queue::fake();

        try {
            $this->bookSlot($doctor, 'inactive-service-session', $service);
            $this->fail('Expected inactive service booking to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The selected service is not available to this hospital.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('appointment_requests', [
            'session_id' => 'inactive-service-session',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_booking_rejects_service_from_another_hospital(): void
    {
        [$hospital, $doctor] = $this->createBookingContext('catalog-book-foreign-owner');
        $otherHospital = $this->createHospital('catalog-book-foreign-service');
        $service = $this->createService($otherHospital);
        $this->bindHospital($hospital);
        $this->travelTo('2026-10-02 08:00:00');
        Queue::fake();

        try {
            $this->bookSlot($doctor, 'foreign-service-session', $service);
            $this->fail('Expected cross-hospital service booking to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'The selected service is not available to this hospital.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('appointment_requests', [
            'session_id' => 'foreign-service-session',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_free_service_skips_stk_and_confirms_directly(): void
    {
        [$hospital, $doctor] = $this->createBookingContext('catalog-book-free');
        $service = $this->createService($hospital, ['price' => 0]);
        $this->travelTo('2026-10-02 08:00:00');
        Queue::fake();

        $appointment = $this->bookSlot($doctor, 'free-service-session', $service);

        $this->assertDatabaseHas('appointment_requests', [
            'id' => $appointment->id,
            'service_id' => $service->id,
            'payment_amount' => 0,
            'booking_fee' => 0,
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_tenant_booking_page_exposes_services(): void
    {
        $this->withoutVite();
        $hospital = $this->createHospital('catalog-tenant-home');
        $this->bindHospital($hospital);
        $patient = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
            'is_doctor' => false,
        ]);
        $service = $this->createService($hospital, [
            'name' => 'Cardiology Consultation',
            'price' => 2500,
            'duration_minutes' => 45,
        ]);
        $otherHospital = $this->createHospital('catalog-tenant-home-foreign');
        $this->createService($otherHospital, ['name' => 'Foreign Service']);
        $this->bindHospital($hospital);

        $this->actingAs($patient)
            ->get(route('tenant.home', $hospital->slug))
            ->assertSee($service->name)
            ->assertSee('KSh 2,500.00')
            ->assertSee('(45 min)')
            ->assertSee('name="service_id"', false)
            ->assertSee('Select a service')
            ->assertDontSee('Foreign Service');
    }

    public function test_tenant_home_displays_its_own_standard_booking_deposit(): void
    {
        $this->withoutVite();
        $hospital = $this->createHospital('catalog-deposit-owner');
        $hospital->update(['deposit_amount' => 2345]);
        $otherHospital = $this->createHospital('catalog-deposit-foreign');
        $otherHospital->update(['deposit_amount' => 7654]);

        $this->get(route('tenant.home', $hospital->slug))
            ->assertSee('standard booking deposit of')
            ->assertSee('KSh 2,345.00')
            ->assertDontSee('KSh 7,654.00');
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
            'deposit_amount' => 700,
            'mpesa_shortcode' => '174379',
            'mpesa_consumer_key' => 'test-consumer-key',
            'mpesa_consumer_secret' => 'test-consumer-secret',
            'mpesa_passkey' => 'test-passkey',
        ]);
    }

    /**
     * @return array{Hospital, User}
     */
    private function createBookingContext(string $slug, int $depositAmount = 700): array
    {
        $hospital = $this->createHospital($slug);
        $hospital->update(['deposit_amount' => $depositAmount]);
        $this->bindHospital($hospital);
        $doctor = User::factory()->create([
            'is_doctor' => true,
            'specialization' => 'General',
        ]);
        DoctorAvailability::factory()->for($doctor, 'doctor')->create([
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '10:00',
            'slot_duration_minutes' => 30,
        ]);

        return [$hospital, $doctor];
    }

    private function bindHospital(Hospital $hospital): void
    {
        app()->instance('currentHospital', $hospital);
    }

    private function bookSlot(User $doctor, string $sessionId, ?Service $service = null): AppointmentRequest
    {
        return app(DoctorAvailabilityService::class)->bookAppointment(
            '2026-10-05',
            '09:00',
            [
                'name' => 'Catalog Test Patient',
                'phone' => '254748249882',
                'email' => 'catalog@example.test',
                'reason' => 'Consultation',
                'raw_message' => 'Book a service appointment.',
                'session_id' => $sessionId,
            ],
            $doctor->id,
            $service,
        ) ?? throw new \RuntimeException('Expected the doctor slot to be available.');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createService(Hospital $hospital, array $attributes = []): Service
    {
        $previousHospital = app()->bound('currentHospital') ? hospital() : null;
        app()->instance('currentHospital', $hospital);
        $service = Service::factory()->create($attributes);

        if ($previousHospital) {
            app()->instance('currentHospital', $previousHospital);
        } else {
            app()->forgetInstance('currentHospital');
        }

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
