<?php

namespace Database\Factories;

use App\Models\Hospital;
use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationLog>
 */
class NotificationLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'hospital_id' => Hospital::factory(),
            'channel' => fake()->randomElement(['sms', 'whatsapp']),
            'provider' => fake()->randomElement(['twilio', 'africastalking', 'meta_whatsapp']),
            'status' => fake()->randomElement(['pending', 'sent', 'failed', 'not_configured']),
            'http_status' => null,
            'provider_message_id' => null,
            'failure_code' => null,
            'provider_response' => null,
        ];
    }
}
