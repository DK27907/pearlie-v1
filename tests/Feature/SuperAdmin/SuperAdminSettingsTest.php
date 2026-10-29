<?php

namespace Tests\Feature\SuperAdmin;

use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use App\Models\User;
use App\Services\HospitalSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_super_admin_can_save_whatsapp_settings_for_impersonated_hospital(): void
    {
        $hospital = Hospital::factory()->create();
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);
        $credentials = [
            'phone_number_id' => '123456789',
            'access_token' => 'test-access-token',
            'verify_token' => 'test-verify-token',
            'app_secret' => 'test-app-secret',
            'api_version' => 'v21.0',
        ];

        $response = $this->actingAs($superAdmin)
            ->withSession(['impersonating_hospital_id' => $hospital->id])
            ->put(route('admin.integration-settings.whatsapp.update'), $credentials);

        $response->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'whatsapp']))
            ->assertSessionHas('status', 'WhatsApp settings saved.');
        $this->assertSame(
            $credentials,
            app(HospitalSettings::class)->for($hospital)->credential(HospitalIntegrationCredential::PROVIDER_WHATSAPP),
        );
    }

    public function test_super_admin_cannot_save_hospital_settings_without_impersonating_a_hospital(): void
    {
        $hospital = Hospital::factory()->create();
        $superAdmin = User::factory()->create([
            'role' => 'super_admin',
            'is_super_admin' => true,
        ]);

        $this->actingAs($superAdmin)
            ->put(route('admin.integration-settings.whatsapp.update'), [
                'phone_number_id' => '123456789',
                'access_token' => 'test-access-token',
                'verify_token' => 'test-verify-token',
                'app_secret' => 'test-app-secret',
                'api_version' => 'v21.0',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('hospital_integration_credentials', [
            'hospital_id' => $hospital->id,
            'provider' => HospitalIntegrationCredential::PROVIDER_WHATSAPP,
        ]);
    }
}
