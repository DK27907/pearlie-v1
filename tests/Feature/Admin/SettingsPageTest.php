<?php

namespace Tests\Feature\Admin;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
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
            ->assertSee('Email')
            ->assertSee('name="booking_payment_mode"', false)
            ->assertSee('Cash at the hospital only')
            ->assertSee('No payment required');
    }

    public function test_branding_tab_does_not_decrypt_unrelated_integration_credentials(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $credential = HospitalIntegrationCredential::factory()->create([
            'hospital_id' => $hospital->id,
            'provider' => 'whatsapp',
        ]);
        DB::table('hospital_integration_credentials')
            ->where('id', $credential->id)
            ->update(['credentials' => 'invalid-encrypted-value']);

        $this->actingAs($admin)
            ->get(route('admin.integration-settings.edit', ['tab' => 'branding']))
            ->assertOk()
            ->assertSee('Hospital logo');
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
            'reminder_enabled' => '1',
            'reminder_message_template' => 'Hello {patient_name}, your appointment is tomorrow.',
            'business_hours' => [
                'monday' => ['open' => '08:00', 'close' => '17:00', 'closed' => '0'],
            ],
        ])->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'general']));

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'deposit_amount' => '750.00',
            'slot_duration_minutes' => 45,
            'auto_confirm_paid_appointments' => true,
            'reminder_enabled' => true,
        ]);
        $this->assertSame(
            'Hello {patient_name}, your appointment is tomorrow.',
            $hospital->fresh()->reminder_message_template,
        );
        $this->assertSame('08:00', $hospital->fresh()->business_hours['monday']['open']);
    }

    public function test_admin_can_change_booking_payment_mode_without_changing_existing_appointments(): void
    {
        $hospital = Hospital::factory()->create();
        $otherHospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'payment_status' => 'pending',
            'payment_method' => 'mpesa',
            'payment_due_at' => 'online',
        ]);

        $this->actingAs($admin)->put(route('admin.integration-settings.general.update'), [
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'booking_payment_mode' => Hospital::BOOKING_PAYMENT_MODE_NONE,
            'hospital_id' => $otherHospital->id,
        ])->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'general']));

        $this->assertSame(Hospital::BOOKING_PAYMENT_MODE_NONE, $hospital->fresh()->bookingPaymentMode());
        $this->assertSame(Hospital::BOOKING_PAYMENT_MODE_MPESA, $otherHospital->fresh()->bookingPaymentMode());
        $this->assertSame('pending', $appointment->fresh()->payment_status);
        $this->assertSame('mpesa', $appointment->fresh()->payment_method);
        $this->assertSame('online', $appointment->fresh()->payment_due_at);
    }

    public function test_admin_can_update_hospital_information_and_tenant_pages_show_it(): void
    {
        $hospital = Hospital::factory()->create(['deposit_amount' => '500.00']);
        $admin = $this->createAdmin($hospital);
        $updatedName = 'Pearl Hospital Audit';
        $updatedPhone = '254748249882';
        $updatedAddress = 'Audit Avenue, Nyeri';

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.general.update'), [
                'name' => $updatedName,
                'phone' => $updatedPhone,
                'address' => $updatedAddress,
                'deposit_amount' => $hospital->deposit_amount,
                'slot_duration_minutes' => 30,
            ])
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'general']));

        $hospital->refresh();
        $this->assertSame($updatedName, $hospital->name);
        $this->assertSame($updatedPhone, $hospital->phone);
        $this->assertSame($updatedAddress, $hospital->address);
        $this->assertSame('500.00', $hospital->deposit_amount);

        $this->get(route('tenant.home', ['slug' => $hospital->slug]))
            ->assertSee($updatedName)
            ->assertSee($updatedPhone)
            ->assertSee($updatedAddress);
        $this->get(route('tenant.chat.page', ['slug' => $hospital->slug]))
            ->assertSee($updatedName);
    }

    public function test_provider_settings_tabs_render_a_warning_for_unreadable_credentials(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);

        foreach (['mpesa', 'whatsapp', 'sms', 'email'] as $provider) {
            $credential = HospitalIntegrationCredential::factory()->create([
                'hospital_id' => $hospital->id,
                'provider' => $provider,
            ]);
            DB::table('hospital_integration_credentials')
                ->where('id', $credential->id)
                ->update(['credentials' => 'not-valid-ciphertext']);

            $this->actingAs($admin)
                ->get(route('admin.integration-settings.edit', ['tab' => $provider]))
                ->assertOk()
                ->assertSee('Stored integration credentials are unreadable.')
                ->assertSee('Please re-enter them to restore the integration.');
        }
    }

    public function test_admin_can_reenter_whatsapp_credentials_after_decryption_failure(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);
        $credential = HospitalIntegrationCredential::factory()->create([
            'hospital_id' => $hospital->id,
            'provider' => 'whatsapp',
        ]);
        DB::table('hospital_integration_credentials')
            ->where('id', $credential->id)
            ->update(['credentials' => 'not-valid-ciphertext']);
        $credentials = [
            'phone_number_id' => '123456789',
            'access_token' => 'replacement-access-token',
            'verify_token' => 'replacement-verify-token',
            'app_secret' => 'replacement-app-secret',
            'api_version' => 'v21.0',
        ];

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.whatsapp.update'), $credentials)
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'whatsapp']));

        $this->assertSame($credentials, app(HospitalSettings::class)->for($hospital)->credential('whatsapp'));
    }

    public function test_admin_can_update_appointment_lifecycle_policy(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)->put(route('admin.integration-settings.general.update'), [
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'patient_cancellation_enabled' => '0',
            'patient_rescheduling_enabled' => '1',
            'cancellation_cutoff_hours' => 36,
            'rescheduling_cutoff_hours' => 48,
            'late_cancellation_fee' => 275.50,
            'deposits_refundable' => '0',
        ])->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'general']));

        $hospital->refresh();
        $this->assertFalse($hospital->patient_cancellation_enabled);
        $this->assertTrue($hospital->patient_rescheduling_enabled);
        $this->assertSame(36, $hospital->cancellation_cutoff_hours);
        $this->assertSame(48, $hospital->rescheduling_cutoff_hours);
        $this->assertSame('275.50', $hospital->late_cancellation_fee);
        $this->assertFalse($hospital->deposits_refundable);
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

        $this->assertSame('500.00', $hospital->fresh()->deposit_amount);
    }

    public function test_general_settings_reject_an_unsupported_booking_payment_mode(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = $this->createAdmin($hospital);

        $this->actingAs($admin)->putJson(route('admin.integration-settings.general.update'), [
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'booking_payment_mode' => 'crypto',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('booking_payment_mode');

        $this->assertSame(Hospital::BOOKING_PAYMENT_MODE_MPESA, $hospital->fresh()->bookingPaymentMode());
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
