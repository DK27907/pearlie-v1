<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 500, 5000),
            'duration_minutes' => fake()->randomElement([15, 30, 60]),
            'category' => fake()->randomElement(['General', 'Dental', 'Specialist', 'Diagnostic']),
            'requires_specialty' => null,
            'is_active' => true,
        ];
    }
}
