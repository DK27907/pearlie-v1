<?php

namespace Tests\Feature;

use App\Models\DoctorAvailability;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DoctorUserSeeder;
use Database\Seeders\HospitalSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoAccountSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_seeders_create_the_documented_hospital_accounts(): void
    {
        $this->seed([
            HospitalSeeder::class,
            RoleSeeder::class,
            SuperAdminSeeder::class,
            AdminUserSeeder::class,
            DoctorUserSeeder::class,
        ]);

        $expectedAccounts = [
            'super@axiomforge.co.ke',
            'admin+pearl@example.test',
            'admin+demo@example.test',
            'doctor1@pearl.test',
            'doctor2@pearl.test',
            'doctor3@pearl.test',
            'doctor4@pearl.test',
            'doctor5@pearl.test',
            'doctor1@demohospital.co.ke',
            'doctor2@demohospital.co.ke',
        ];
        $accounts = User::withoutGlobalScopes()
            ->whereIn('email', $expectedAccounts)
            ->get()
            ->keyBy('email');

        $this->assertEqualsCanonicalizing($expectedAccounts, $accounts->keys()->all());
        foreach ($accounts as $account) {
            $this->assertTrue(Hash::check('password', $account->password), $account->email);
        }

        $doctors = $accounts->filter(fn (User $user): bool => $user->is_doctor);
        $this->assertCount(7, $doctors);
        foreach ($doctors as $doctor) {
            $availabilities = DoctorAvailability::withoutGlobalScopes()
                ->where('doctor_id', $doctor->id)
                ->orderBy('day_of_week')
                ->get()
                ->keyBy('day_of_week');

            $this->assertCount(7, $availabilities);
            $this->assertSame('08:00', $availabilities->first()->start_time);
            $this->assertSame('17:00', $availabilities->first()->end_time);

            if (str_ends_with($doctor->email, '@pearl.test')) {
                $this->assertFalse($availabilities[0]->is_active);
                foreach (range(1, 6) as $dayOfWeek) {
                    $this->assertTrue($availabilities[$dayOfWeek]->is_active);
                }

                continue;
            }

            foreach (range(0, 6) as $dayOfWeek) {
                $this->assertTrue($availabilities[$dayOfWeek]->is_active);
            }
        }
    }
}
