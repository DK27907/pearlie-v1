<?php

namespace Database\Factories;

use App\Models\DoctorAvailability;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DoctorAvailability>
 */
class DoctorAvailabilityFactory extends Factory
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
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => config('pearlie.appointment.default_schedule.start_time'),
            'end_time' => config('pearlie.appointment.default_schedule.end_time'),
            'slot_duration_minutes' => config('pearlie.appointment.slot_duration_minutes'),
            'max_patients_per_slot' => 1,
            'is_active' => true,
        ];
    }
}
