<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(HospitalSeeder::class);
        $pearl = \App\Models\Hospital::query()->where('slug', 'pearl')->firstOrFail();
        app()->instance('currentHospital', $pearl);
        $this->call(RoleSeeder::class);

        if (app()->isProduction()) {
            return;
        }

        $this->call(SuperAdminSeeder::class);
        $this->call(AdminUserSeeder::class);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(DoctorUserSeeder::class);
        $this->call(KnowledgeBaseSeeder::class);
    }
}
