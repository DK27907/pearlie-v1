<?php

namespace Database\Factories;

use App\Models\DoctorUnavailableDate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorUnavailableDate>
 */
class DoctorUnavailableDateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => User::factory(),
            'date' => fake()->dateTimeBetween('tomorrow', '+1 year')->format('Y-m-d'),
            'reason' => fake()->sentence(),
        ];
    }
}
