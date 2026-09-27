<?php

namespace Tests\Feature;

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
            'admin@pearlhospital.co.ke',
            'admin@demohospital.co.ke',
            'doctor1@pearlhospital.co.ke',
            'doctor2@pearlhospital.co.ke',
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
        $this->assertCount(4, $doctors);
        foreach ($doctors as $doctor) {
            $this->assertCount(5, $doctor->availabilities);
            $this->assertSame('09:00', $doctor->availabilities->first()->start_time);
            $this->assertSame('17:00', $doctor->availabilities->first()->end_time);
        }
    }
}
