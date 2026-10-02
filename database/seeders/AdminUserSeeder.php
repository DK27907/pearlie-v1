<?php

namespace Database\Seeders;

use App\Models\Hospital;
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
            ['slug' => config('pearlie.default_hospital_slug', 'pearl'), 'email' => config('admin.email')],
        ];
        if (! app()->isProduction()) {
            $accounts[] = [
                'slug' => 'demo',
                'email' => 'admin+demo@example.test',
            ];
        }

        try {
            Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => 'hospital_admin', 'guard_name' => 'web']);

            foreach ($accounts as $account) {
                $hospital = Hospital::query()->where('slug', $account['slug'])->firstOrFail();
                app()->instance('currentHospital', $hospital);
                $email = $account['email'] ?: 'admin+'.$hospital->slug.'@example.test';
                $user = User::withoutGlobalScopes()->firstOrNew(['email' => $email]);
                $user->forceFill([
                    'hospital_id' => $hospital->id,
                    'name' => $hospital->name.' Administrator',
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

                $this->command->info("Hospital administrator created/updated: {$email}");
            }
        } catch (\Throwable $e) {
            $this->command->warn('Spatie roles not available yet: '.$e->getMessage());
        } finally {
            app()->instance('currentHospital', $previousHospital);
        }
    }
}
