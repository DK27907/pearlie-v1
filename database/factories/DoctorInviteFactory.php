<?php

namespace Database\Factories;

use App\Models\DoctorInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DoctorInvite>
 */
class DoctorInviteFactory extends Factory
{
    protected $model = DoctorInvite::class;

    public function definition(): array
    {
        return [
            'doctor_id' => User::factory()->state(['is_doctor' => true]),
            'token' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(3),
            'used_at' => null,
        ];
    }
}
