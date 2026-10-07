<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use App\Services\HospitalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class HospitalSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_hospital_column_value(): void
    {
        $hospital = Hospital::factory()->create(['deposit_amount' => 750]);

        $value = (new HospitalSettings)->for($hospital)->get('deposit_amount');

        $this->assertSame(750, $value);
    }

    public function test_get_falls_back_to_env_config(): void
    {
        config(['pearlie.appointment.deposit_amount' => 725]);
        $hospital = Hospital::factory()->create();

        $value = (new HospitalSettings)->for($hospital)->get('appointment.deposit_amount');

        $this->assertSame(725, $value);
    }

    public function test_get_returns_explicit_default(): void
    {
        $hospital = Hospital::factory()->create();

        $value = (new HospitalSettings)->for($hospital)->get('unconfigured.value', 'fallback');

        $this->assertSame('fallback', $value);
    }

    public function test_set_updates_hospital_column(): void
    {
        $hospital = Hospital::factory()->create(['deposit_amount' => 500]);

        (new HospitalSettings)->for($hospital)->set('deposit_amount', 999);

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'deposit_amount' => 999,
        ]);
    }

    public function test_set_throws_on_unmappable_key(): void
    {
        $hospital = Hospital::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new HospitalSettings)->for($hospital)->set('nonsense_key', 'x');
    }

    public function test_set_credential_encrypts_and_stores(): void
    {
        $hospital = Hospital::factory()->create();
        $credentials = ['consumer_secret' => 'plaintext-secret-value'];

        (new HospitalSettings)->for($hospital)->setCredential('mpesa', $credentials);

        $this->assertDatabaseHas('hospital_integration_credentials', [
            'hospital_id' => $hospital->id,
            'provider' => HospitalIntegrationCredential::PROVIDER_MPESA,
        ]);
        $rawCredentials = DB::table('hospital_integration_credentials')
            ->where('hospital_id', $hospital->id)
            ->where('provider', HospitalIntegrationCredential::PROVIDER_MPESA)
            ->value('credentials');
        $this->assertIsString($rawCredentials);
        $this->assertStringNotContainsString('plaintext-secret-value', $rawCredentials);
    }

    public function test_credential_returns_decrypted_array(): void
    {
        $hospital = Hospital::factory()->create();
        $credentials = [
            'consumer_key' => 'test-key',
            'consumer_secret' => 'test-secret',
            'environment' => 'sandbox',
        ];
        $settings = (new HospitalSettings)->for($hospital);
        $settings->setCredential('mpesa', $credentials);

        $this->assertSame($credentials, $settings->credential('mpesa'));
    }

    public function test_credential_returns_null_when_not_set(): void
    {
        $hospital = Hospital::factory()->create();

        $this->assertNull((new HospitalSettings)->for($hospital)->credential('whatsapp'));
    }

    public function test_set_credential_updates_existing_row(): void
    {
        $hospital = Hospital::factory()->create();
        $settings = (new HospitalSettings)->for($hospital);
        $settings->setCredential('mpesa', ['consumer_key' => 'old-key']);

        $settings->setCredential('mpesa', ['consumer_key' => 'new-key']);

        $this->assertSame(1, DB::table('hospital_integration_credentials')
            ->where('hospital_id', $hospital->id)
            ->where('provider', HospitalIntegrationCredential::PROVIDER_MPESA)
            ->count());
        $this->assertSame(['consumer_key' => 'new-key'], $settings->credential('mpesa'));
    }

    public function test_delete_credential_removes_row(): void
    {
        $hospital = Hospital::factory()->create();
        $settings = (new HospitalSettings)->for($hospital);
        $settings->setCredential('whatsapp', ['access_token' => 'temporary-token']);

        $settings->deleteCredential('whatsapp');

        $this->assertDatabaseMissing('hospital_integration_credentials', [
            'hospital_id' => $hospital->id,
            'provider' => HospitalIntegrationCredential::PROVIDER_WHATSAPP,
        ]);
    }

    public function test_set_credential_throws_on_unknown_provider(): void
    {
        $hospital = Hospital::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new HospitalSettings)->for($hospital)->setCredential('twitter', ['token' => 'x']);
    }

    public function test_service_requires_hospital_binding(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No hospital bound. Call for() first.');

        (new HospitalSettings)->set('nonsense_key', 'x');
    }
}
