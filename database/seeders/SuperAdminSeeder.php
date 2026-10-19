<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('admin.super_admin_email', 'super@axiomforge.co.ke');
        $password = config('admin.super_admin_password');

        if (app()->isProduction() && blank($password)) {
            return;
        }

        $hospital = Hospital::query()->where('slug', 'pearl')->firstOrFail();
        $user = User::withoutGlobalScopes()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'hospital_id' => $hospital->id,
            'name' => 'MediDesk Platform Administrator',
            'password' => Hash::make((string) ($password ?: 'password')),
            'email_verified_at' => now(),
            'role' => 'super_admin',
            'is_super_admin' => true,
            'is_admin' => false,
        ])->save();

        $user->syncRolesFromFlags();
    }
}
