<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD', 'password');

        $user = User::firstOrNew(['email' => $email]);
        $user->name = 'Admin';
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->is_admin = true;
        $user->save();

        // Ensure 'admin' role exists and assign using Spatie permission package
        try {
            $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
            if (! $user->hasRole('admin')) {
                $user->assignRole('admin');
            }
        } catch (\Throwable $e) {
            $this->command->warn('Spatie roles not available yet: ' . $e->getMessage());
        }

        $this->command->info("Admin user created/updated: {$email}");
    }
}
