<?php

namespace Database\Factories;

use App\Models\AppointmentRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentRequest>
 */
class AppointmentRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => fake()->unique()->uuid(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('2547########'),
            'preferred_date' => today()->addDay(),
            'reason' => fake()->sentence(),
            'raw_message' => fake()->sentence(),
            'status' => AppointmentRequest::STATUS_PENDING,
            'booking_fee' => 500,
            'payment_status' => 'pending',
        ];
    }
}
