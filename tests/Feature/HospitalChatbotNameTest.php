<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HospitalChatbotNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_custom_chatbot_name_is_used_when_set(): void
    {
        $hospital = Hospital::factory()->create([
            'name' => 'Amina Medical Center',
            'chatbot_name' => 'Aminie',
        ]);

        $this->assertSame('Aminie', $hospital->chatbotName());
    }

    public function test_chatbot_name_derives_from_hospital_name_when_null(): void
    {
        $pearl = Hospital::factory()->create([
            'name' => 'Pearl Hospital',
            'chatbot_name' => null,
        ]);
        $amina = Hospital::factory()->create([
            'name' => 'Amina Medical Center',
            'chatbot_name' => null,
        ]);

        $this->assertSame('Pearlie', $pearl->chatbotName());
        $this->assertSame('Aminie', $amina->chatbotName());
    }

    public function test_tenant_chat_page_uses_hospital_chatbot_name(): void
    {
        $this->pearlHospital();

        $this->get('/h/pearl/chat')
            ->assertSee('Pearlie AI Assistant')
            ->assertSee('Pearlie is thinking');
    }

    public function test_two_hospitals_show_different_chatbot_names(): void
    {
        $this->pearlHospital();
        Hospital::factory()->create([
            'name' => 'Amina Medical Center',
            'slug' => 'amina',
            'chatbot_name' => null,
        ]);

        $this->get('/h/pearl/chat')
            ->assertSee('Pearlie AI Assistant')
            ->assertSee('Pearlie is thinking');
        $this->get('/h/amina/chat')
            ->assertSee('Aminie AI Assistant')
            ->assertSee('Aminie is thinking')
            ->assertDontSee('Pearlie');
    }

    public function test_admin_can_update_chatbot_name(): void
    {
        $hospital = $this->pearlHospital();
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.integration-settings.edit', ['tab' => 'branding']))
            ->assertSee('Chatbot name')
            ->assertSee('The name patients see when chatting with your AI assistant. Leave blank to auto-derive from the hospital name.')
            ->assertSee('value="Pearlie"', false);

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'chatbot_name' => 'Custom Bot',
            ])
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'branding']));

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'chatbot_name' => 'Custom Bot',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.integration-settings.branding.update'), [
                'chatbot_name' => '',
            ])
            ->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'branding']));

        $this->assertNull($hospital->fresh()->chatbot_name);
        $this->assertSame('Pearlie', $hospital->fresh()->chatbotName());
    }

    public function test_superadmin_header_ignores_hospital_chatbot_name(): void
    {
        $hospital = $this->pearlHospital();
        $superadmin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_super_admin' => true,
            'is_admin' => false,
            'role' => 'super_admin',
        ]);

        $this->actingAs($superadmin)
            ->get(route('superadmin.dashboard'))
            ->assertSee('MediDesk AI')
            ->assertDontSee('Pearlie');
    }

    public function test_chatbot_name_validation_max_length(): void
    {
        $hospital = $this->pearlHospital();
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->actingAs($admin)
            ->putJson(route('admin.integration-settings.branding.update'), [
                'chatbot_name' => str_repeat('A', 61),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('chatbot_name');

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'chatbot_name' => 'Pearlie',
        ]);
    }

    private function pearlHospital(): Hospital
    {
        $hospital = Hospital::withoutGlobalScopes()->firstOrNew(['slug' => 'pearl']);
        $hospital->forceFill([
            'name' => 'Pearl Hospital',
            'chatbot_name' => 'Pearlie',
            'is_active' => true,
            'subscription_status' => 'active',
        ])->save();

        return $hospital;
    }
}
