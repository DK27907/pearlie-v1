<?php

namespace Tests\Feature\Admin;

use App\Models\Hospital;
use App\Models\User;
use App\Services\HospitalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_view_settings_page(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)->get(route('admin.integration-settings.edit'))
            ->assertOk()
            ->assertSee('General')
            ->assertSee('M-Pesa')
            ->assertSee('WhatsApp')
            ->assertSee('SMS')
            ->assertSee('Email');
    }

    public function test_non_admin_cannot_view_settings_page(): void
    {
        $hospital = Hospital::factory()->create();
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
        ]);

        $this->actingAs($doctor)->get(route('admin.integration-settings.edit'))->assertForbidden();
    }

    public function test_admin_can_update_general_settings(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)->put(route('admin.integration-settings.general.update'), [
            'deposit_amount' => 750,
            'slot_duration_minutes' => 45,
            'auto_confirm_paid_appointments' => '1',
            'business_hours' => [
                'monday' => ['open' => '08:00', 'close' => '17:00', 'closed' => '0'],
            ],
        ])->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'general']));

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'deposit_amount' => 750,
            'slot_duration_minutes' => 45,
            'auto_confirm_paid_appointments' => true,
        ]);
        $this->assertSame('08:00', $hospital->fresh()->business_hours['monday']['open']);
    }

    public function test_general_settings_validation_rejects_bad_values(): void
    {
        $hospital = Hospital::factory()->create(['deposit_amount' => 500]);
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)->putJson(route('admin.integration-settings.general.update'), [
            'deposit_amount' => -1,
            'slot_duration_minutes' => 30,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('deposit_amount');

        $this->assertSame(500, $hospital->fresh()->deposit_amount);
    }

    public function test_admin_can_save_mpesa_credentials(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $credentials = $this->mpesaData();

        $this->actingAs($admin)->put(route('admin.integration-settings.mpesa.update'), $credentials)
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'mpesa']));

        $this->assertSame($credentials, app(HospitalSettings::class)->for($hospital)->credential('mpesa'));
    }

    public function test_mpesa_credentials_are_encrypted_at_rest(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)->put(route('admin.integration-settings.mpesa.update'), $this->mpesaData());

        $rawCredentials = DB::table('hospital_integration_credentials')
            ->where('hospital_id', $hospital->id)
            ->where('provider', 'mpesa')
            ->value('credentials');

        $this->assertIsString($rawCredentials);
        $this->assertFalse(str_contains($rawCredentials, 'test_consumer_secret'));
    }

    public function test_blank_secret_field_keeps_existing_credential(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $initial = $this->mpesaData();

        $this->actingAs($admin)->put(route('admin.integration-settings.mpesa.update'), $initial);
        $initial['passkey'] = '';
        $this->actingAs($admin)->put(route('admin.integration-settings.mpesa.update'), $initial)
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'mpesa']));

        $this->assertSame('test_passkey', app(HospitalSettings::class)->for($hospital)->credential('mpesa')['passkey']);
    }

    public function test_admin_can_clear_mpesa_credentials(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        app(HospitalSettings::class)->for($hospital)->setCredential('mpesa', $this->mpesaData());

        $this->actingAs($admin)->delete(route('admin.integration-settings.credentials.clear', 'mpesa'))
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'mpesa']));

        $this->assertNull(app(HospitalSettings::class)->for($hospital)->credential('mpesa'));
    }

    public function test_admin_cannot_touch_another_hospitals_settings(): void
    {
        $hospitalA = Hospital::factory()->create();
        $hospitalB = Hospital::factory()->create();
        $admin = $this->createAdmin($hospitalA);
        $data = $this->mpesaData();

        $this->actingAs($admin)->put(route('admin.integration-settings.mpesa.update'), [
            ...$data,
            'hospital_id' => $hospitalB->id,
        ]);

        $this->assertNotNull(app(HospitalSettings::class)->for($hospitalA)->credential('mpesa'));
        $this->assertNull(app(HospitalSettings::class)->for($hospitalB)->credential('mpesa'));
    }

    public function test_whatsapp_credentials_round_trip(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $credentials = [
            'phone_number_id' => '123456789',
            'access_token' => 'test-access-token',
            'verify_token' => 'test-verify-token',
            'app_secret' => 'test-app-secret',
            'api_version' => 'v21.0',
        ];

        $this->actingAs($admin)->put(route('admin.integration-settings.whatsapp.update'), $credentials)
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'whatsapp']));

        $this->assertSame($credentials, app(HospitalSettings::class)->for($hospital)->credential('whatsapp'));
    }

    public function test_sms_credentials_round_trip(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $credentials = [
            'provider' => 'africastalking',
            'username' => 'test-account',
            'api_key' => 'test-sms-api-key',
            'sender_id' => 'MEDIDESK',
        ];

        $this->actingAs($admin)->put(route('admin.integration-settings.sms.update'), $credentials)
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'sms']));

        $this->assertSame($credentials, app(HospitalSettings::class)->for($hospital)->credential('sms'));
    }

    public function test_email_credentials_round_trip(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $credentials = [
            'mailer' => 'resend',
            'from_address' => 'appointments@example.test',
            'from_name' => 'Pearl Hospital',
            'resend_api_key' => 'test-resend-api-key',
        ];

        $this->actingAs($admin)->put(route('admin.integration-settings.email.update'), $credentials)
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'email']));

        $this->assertSame($credentials, app(HospitalSettings::class)->for($hospital)->credential('email'));
    }

    /**
     * @return array<string, string>
     */
    private function mpesaData(): array
    {
        return [
            'consumer_key' => 'test_consumer_key',
            'consumer_secret' => 'test_consumer_secret',
            'shortcode' => '174379',
            'passkey' => 'test_passkey',
            'environment' => 'sandbox',
            'callback_url' => 'https://example.test/mpesa/callback',
        ];
    }

    private function createAdmin(Hospital $hospital): User
    {
        return User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
        ]);
    }
}
