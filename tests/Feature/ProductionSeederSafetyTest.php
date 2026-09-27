<?php

namespace Tests\Feature;

use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DoctorUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProductionSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_does_not_create_development_accounts_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        app(DatabaseSeeder::class)->run();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_sample_doctor_seeder_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        $this->expectException(RuntimeException::class);

        app(DoctorUserSeeder::class)->run();
    }

    public function test_admin_seeder_requires_a_password_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');
        config(['admin.password' => null]);
        $this->expectException(RuntimeException::class);

        app(AdminUserSeeder::class)->run();
    }
}
