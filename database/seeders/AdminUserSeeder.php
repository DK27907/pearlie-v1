<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AdminUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $previousHospital = hospital();
        $password = config('admin.password');

        if (app()->isProduction() && blank($password)) {
            throw new RuntimeException('Set ADMIN_PASSWORD before seeding the production administrator.');
        }

        $password = (string) ($password ?: 'password');

        $accounts = [
            ['slug' => 'pearl', 'email' => (string) config('admin.email', 'admin@pearlhospital.co.ke'), 'name' => 'Pearl Hospital Administrator'],
        ];
        if (! app()->isProduction()) {
            $accounts[] = [
                'slug' => 'demo',
                'email' => 'admin@demohospital.co.ke',
                'name' => 'Demo Hospital Administrator',
            ];
        }

        try {
            Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => 'hospital_admin', 'guard_name' => 'web']);

            foreach ($accounts as $account) {
                $hospital = \App\Models\Hospital::query()->where('slug', $account['slug'])->firstOrFail();
                app()->instance('currentHospital', $hospital);
                $user = User::withoutGlobalScopes()->firstOrNew(['email' => $account['email']]);
                $user->forceFill([
                    'hospital_id' => $hospital->id,
                    'name' => $account['name'],
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'is_admin' => true,
                    'role' => 'hospital_admin',
                ])->save();

                if (! $user->hasRole('hospital_admin')) {
                    $user->assignRole('hospital_admin');
                }
                if (! $user->hasRole('admin')) {
                    $user->assignRole('admin');
                }

                $this->command->info("Hospital administrator created/updated: {$account['email']}");
            }
        } catch (\Throwable $e) {
            $this->command->warn('Spatie roles not available yet: '.$e->getMessage());
        } finally {
            app()->instance('currentHospital', $previousHospital);
        }
    }
}
