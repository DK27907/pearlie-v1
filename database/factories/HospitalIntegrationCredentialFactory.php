<?php

namespace Database\Factories;

use App\Models\HospitalIntegrationCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HospitalIntegrationCredential>
 */
class HospitalIntegrationCredentialFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $provider = fake()->randomElement([
            HospitalIntegrationCredential::PROVIDER_MPESA,
            HospitalIntegrationCredential::PROVIDER_WHATSAPP,
            HospitalIntegrationCredential::PROVIDER_SMS,
            HospitalIntegrationCredential::PROVIDER_EMAIL,
        ]);

        return [
            'provider' => $provider,
            'credentials' => match ($provider) {
                HospitalIntegrationCredential::PROVIDER_MPESA => [
                    'consumer_key' => 'test_key',
                    'consumer_secret' => 'test_secret',
                    'shortcode' => '174379',
                    'passkey' => 'test_passkey',
                    'environment' => 'sandbox',
                ],
                HospitalIntegrationCredential::PROVIDER_WHATSAPP => [
                    'phone_number_id' => 'test_phone_number_id',
                    'access_token' => 'test_access_token',
                    'app_secret' => 'test_app_secret',
                ],
                HospitalIntegrationCredential::PROVIDER_SMS => [
                    'api_key' => 'test_api_key',
                    'sender_id' => 'MEDIDESK',
                ],
                HospitalIntegrationCredential::PROVIDER_EMAIL => [
                    'host' => 'mail.test',
                    'username' => 'test_user',
                    'password' => 'test_password',
                    'port' => 587,
                ],
            },
            'is_active' => true,
        ];
    }
}
