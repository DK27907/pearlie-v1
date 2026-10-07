<?php

namespace Database\Factories;

use App\Models\MpesaPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MpesaPayment>
 */
class MpesaPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'appointment_request_id' => null,
            'checkout_request_id' => fake()->unique()->bothify('ws_CO_##########'),
            'merchant_request_id' => fake()->bothify('##########'),
            'phone' => '254712345678',
            'amount' => 500.00,
            'account_reference' => 'APT-1',
            'transaction_desc' => 'Appointment Deposit',
            'status' => MpesaPayment::STATUS_PENDING,
            'result_code' => null,
            'result_description' => null,
            'mpesa_receipt' => null,
            'callback_payload' => null,
            'processed_at' => null,
            'notified_at' => null,
        ];
    }
}
