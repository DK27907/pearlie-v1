<?php

namespace Tests\Feature;

use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\BookingChatService;
use Database\Seeders\DoctorUserSeeder;
use Database\Seeders\HospitalSeeder;
use Database\Seeders\PearlDoctorAvailabilitySeeder;
use Database\Seeders\PearlHospitalFullServicesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PearlHospitalFullServicesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_clinicians_seed_idempotently_and_appear_in_chat(): void
    {
        $this->seed(HospitalSeeder::class);
        $this->seed(DoctorUserSeeder::class);
        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();

        $this->seed(PearlHospitalFullServicesSeeder::class);
        $this->seed(PearlDoctorAvailabilitySeeder::class);

        $serviceNames = Service::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name');
        $doctors = User::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->get();
        $seededDoctors = $doctors->whereIn(
            'email',
            array_column(PearlHospitalFullServicesSeeder::DOCTORS, 'email'),
        );
        $availabilityCount = DoctorAvailability::withoutGlobalScope('hospital')
            ->where('hospital_id', $hospital->id)
            ->whereIn('doctor_id', $seededDoctors->modelKeys())
            ->count();

        app()->instance('currentHospital', $hospital);
        $response = app(BookingChatService::class)->handle(
            'pearl-catalog-overview-test',
            'What services do you offer?',
            'web',
        );

        foreach (PearlHospitalFullServicesSeeder::SERVICES as $service) {
            $this->assertStringContainsString($service['name'], $response->message);
        }
        $this->assertSame(49, $serviceNames->count());
        $this->assertSame(20, $doctors->count());
        $this->assertSame(15, $seededDoctors->count());
        $this->assertSame(75, $availabilityCount);
        $this->assertSame('hospital_services', $response->source);

        $this->seed(PearlHospitalFullServicesSeeder::class);
        $this->seed(PearlDoctorAvailabilitySeeder::class);

        $this->assertSame(
            49,
            Service::withoutGlobalScopes()->where('hospital_id', $hospital->id)->where('is_active', true)->count(),
        );
        $this->assertSame(
            20,
            User::withoutGlobalScopes()
                ->where('hospital_id', $hospital->id)
                ->where('is_doctor', true)
                ->where('is_active', true)
                ->count(),
        );
        $this->assertSame(
            75,
            DoctorAvailability::withoutGlobalScope('hospital')
                ->where('hospital_id', $hospital->id)
                ->whereIn('doctor_id', $seededDoctors->modelKeys())
                ->count(),
        );
    }

    public function test_sample_catalog_seeders_refuse_to_write_in_production(): void
    {
        $this->seed(HospitalSeeder::class);
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            app(PearlHospitalFullServicesSeeder::class)->run();
            $this->fail('The sample catalog seeder must refuse production.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Pearl sample catalog contains unverified prices, KES 1 test services, and sample .test clinician accounts; it cannot be seeded in production.',
                $exception->getMessage(),
            );
        }

        try {
            app(PearlDoctorAvailabilitySeeder::class)->run();
            $this->fail('The sample availability seeder must refuse production.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Pearl sample doctor availability belongs to the unverified .test clinician roster and cannot be seeded in production.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            10,
            Service::withoutGlobalScopes()->where('hospital_id', 1)->count(),
        );
        $this->assertSame(0, DoctorAvailability::withoutGlobalScope('hospital')->count());
    }
}
