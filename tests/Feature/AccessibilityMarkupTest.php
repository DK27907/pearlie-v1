<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessibilityMarkupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_permission_and_role_controls_have_associated_labels(): void
    {
        $hospital = Hospital::factory()->create();
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
        ]);

        $permissions = $this->actingAs($admin)->get(route('admin.permissions.index'));

        $permissions->assertOk()
            ->assertSee('<label for="permission-name">Name</label>', false)
            ->assertSee('<select id="assign-permission-role" name="role"', false)
            ->assertSee('<select id="assign-permission-permission" name="permission"', false)
            ->assertSee('<select id="revoke-permission-role" name="role"', false)
            ->assertSee('<select id="revoke-permission-permission" name="permission"', false);

        $this->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('<select id="assign-role-user" name="user_id"', false)
            ->assertSee('<select id="assign-role-name" name="role"', false)
            ->assertSee('<select id="remove-role-user" name="user_id"', false)
            ->assertSee('<select id="remove-role-name" name="role"', false);
    }

    public function test_admin_webhook_url_has_readable_contrast_classes(): void
    {
        $hospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $this->actingAs($admin)->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('<code class="rounded bg-slate-100 px-1 text-slate-700">', false);
    }

    public function test_doctor_optional_reason_has_readable_contrast_classes(): void
    {
        $hospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_doctor' => true,
        ]);

        $this->actingAs($doctor)->get(route('doctor.availability'))
            ->assertOk()
            ->assertSee('<span class="font-normal text-slate-600">(optional)</span>', false);
    }

    public function test_conversation_pagination_labels_disabled_controls_without_prohibited_attributes(): void
    {
        $hospital = Hospital::factory()->create(['subscription_plan' => 'enterprise']);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        foreach (range(1, 26) as $number) {
            Conversation::query()->create([
                'hospital_id' => $hospital->id,
                'session_id' => 'accessibility-pagination-'.$number,
                'user_message' => 'Question '.$number,
                'ai_response' => 'Answer '.$number,
                'confidence_score' => 1,
                'channel' => 'web',
                'escalated' => false,
            ]);
        }

        $this->actingAs($admin)->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('class="sr-only"', false)
            ->assertDontSee('aria-label="&laquo; Previous"', false);
    }
}
