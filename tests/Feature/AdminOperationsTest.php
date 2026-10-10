<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Hospital;
use App\Models\KnowledgeBase;
use App\Models\MpesaPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_admin_can_create_service_and_knowledge_base_entries_for_their_tenant(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('content-admin');

        $this->actingAs($admin)
            ->post(route('admin.knowledge-services.store'), [
                'subcategory' => 'cardiology',
                'keywords' => "heart, cardiac\nheart care",
                'question' => 'What cardiac care is available?',
                'answer' => 'Tenant-specific cardiac services.',
            ])
            ->assertRedirect(route('admin.knowledge-services.index'));

        $service = KnowledgeBase::withoutGlobalScopes()->where('subcategory', 'cardiology')->firstOrFail();
        $this->assertSame($hospital->id, $service->hospital_id);
        $this->assertSame(['heart', 'cardiac', 'heart care'], $service->keywords);

        $this->actingAs($admin)
            ->post(route('admin.knowledge-base.store'), [
                'category' => 'visiting',
                'subcategory' => 'visiting-hours',
                'keywords' => 'visitor hours, visiting',
                'question' => 'When can visitors come?',
                'answer' => 'Call the hospital for current visiting hours.',
            ])
            ->assertRedirect(route('admin.knowledge-base.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'hospital_id' => $hospital->id,
            'category' => 'visiting',
            'subcategory' => 'visiting-hours',
        ]);
    }

    public function test_admin_cannot_create_a_service_without_usable_keywords(): void
    {
        [, $admin] = $this->createAdminTenant('invalid-service-admin');

        $this->actingAs($admin)
            ->from(route('admin.knowledge-services.create'))
            ->post(route('admin.knowledge-services.store'), [
                'subcategory' => 'unkeyed',
                'keywords' => ', ;',
                'question' => 'What is this service?',
                'answer' => 'Service details.',
            ])
            ->assertRedirect(route('admin.knowledge-services.create'))
            ->assertSessionHasErrors('keywords');

        $this->assertDatabaseCount('knowledge_bases', 0);
    }

    public function test_admin_service_views_render_legacy_json_encoded_keywords(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('legacy-keywords-admin');
        $service = $this->createKnowledgeEntry($hospital, [
            'category' => 'services',
            'subcategory' => 'cardiology',
            'keywords' => json_encode(['heart', 'cardiac'], JSON_THROW_ON_ERROR),
            'question' => 'What cardiac care is available?',
            'answer' => 'Tenant-specific cardiac services.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.knowledge-services.index'))
            ->assertOk()
            ->assertSee('Keywords: heart, cardiac')
            ->assertSee('data-confirm="Delete this service entry?"', false);

        $this->actingAs($admin)
            ->get(route('admin.knowledge-services.edit', $service))
            ->assertOk()
            ->assertSeeText('heart, cardiac');
    }

    public function test_admin_appointment_payment_verification_uses_the_external_asset(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('payment-verification-admin');
        $appointment = $this->createAppointment($hospital, 'Payment Verification Patient');
        $appointment->update(['mpesa_checkout_request_id' => 'test-checkout-request']);

        $this->actingAs($admin)
            ->get(route('admin.appointments.show', $appointment))
            ->assertOk()
            ->assertSee('id="verify-mpesa-payment"', false)
            ->assertSee('/api/mpesa/status/test-checkout-request?hospital='.$hospital->slug, false)
            ->assertDontSee("document.getElementById('verify-mpesa-payment')", false);
    }

    public function test_admin_appointment_filters_have_associated_labels(): void
    {
        [, $admin] = $this->createAdminTenant('appointment-filter-labels-admin');

        $this->actingAs($admin)
            ->get(route('admin.appointments.index'))
            ->assertOk()
            ->assertSee('<label class="sr-only" for="appointment-search">Search appointments</label>', false)
            ->assertSee('<label class="sr-only" for="appointment-status-filter">Filter by appointment status</label>', false)
            ->assertSee('<label class="sr-only" for="appointment-bulk-action">Bulk appointment action</label>', false)
            ->assertSee('<label class="sr-only" for="appointment-bulk-status">Bulk appointment status</label>', false);
    }

    public function test_site_header_does_not_override_visible_text_with_an_incomplete_accessible_name(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('accessible-header-admin');

        $this->runForHospital($hospital, function () use ($admin): void {
            $this->actingAs($admin)
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertDontSee('aria-label="Test Hospital home"', false);
        });
    }

    public function test_admin_can_update_and_delete_service_and_knowledge_entries(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('manage-content-admin');
        $service = $this->createKnowledgeEntry($hospital, [
            'category' => 'services',
            'subcategory' => 'radiology',
            'keywords' => ['imaging'],
            'question' => 'What imaging is offered?',
            'answer' => 'Initial answer.',
        ]);
        $knowledgeEntry = $this->createKnowledgeEntry($hospital, [
            'category' => 'general',
            'subcategory' => 'visiting',
            'keywords' => ['visitor'],
            'question' => 'What are visiting hours?',
            'answer' => 'Initial visiting answer.',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.knowledge-services.update', $service), [
                'subcategory' => 'radiology',
                'keywords' => 'imaging, x-ray',
                'question' => 'Which imaging services are offered?',
                'answer' => 'Updated imaging answer.',
            ])
            ->assertRedirect(route('admin.knowledge-services.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $service->id,
            'hospital_id' => $hospital->id,
            'answer' => 'Updated imaging answer.',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.knowledge-base.update', $knowledgeEntry), [
                'category' => 'general',
                'subcategory' => 'visiting',
                'keywords' => 'visitor, visiting hours',
                'question' => 'When are visitors allowed?',
                'answer' => 'Updated visiting answer.',
            ])
            ->assertRedirect(route('admin.knowledge-base.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'id' => $knowledgeEntry->id,
            'answer' => 'Updated visiting answer.',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.knowledge-services.destroy', $service))
            ->assertRedirect();
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $service->id]);

        $this->actingAs($admin)
            ->delete(route('admin.knowledge-base.destroy', $knowledgeEntry))
            ->assertRedirect();
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $knowledgeEntry->id]);
    }

    public function test_admin_conversations_and_payments_are_scoped_to_their_tenant(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('records-admin');
        $otherHospital = $this->createHospital('other-records');
        $ownConversation = $this->createConversation($hospital, 'own-session', 'Own patient message.');
        $otherConversation = $this->createConversation($otherHospital, 'other-session', 'Private other-tenant message.');
        $this->createPayment($hospital, 'own-checkout');
        $this->createPayment($otherHospital, 'other-checkout');

        $this->actingAs($admin)
            ->get(route('admin.conversations.index'))
            ->assertOk()
            ->assertSee('Own patient message.')
            ->assertDontSee('Private other-tenant message.');

        $this->actingAs($admin)
            ->get(route('admin.conversations.show', $ownConversation))
            ->assertOk()
            ->assertSee('Own patient message.');

        $this->actingAs($admin)
            ->get(route('admin.conversations.show', $otherConversation->id))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertSee('own-checkout')
            ->assertDontSee('other-checkout');
    }

    public function test_admin_can_save_hospital_profile_branding_ai_and_appointment_settings(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('settings-admin');
        $hospital->update(['settings' => ['existing_setting' => 'preserve']]);

        $this->actingAs($admin)
            ->get(route('admin.settings'))
            ->assertOk()
            ->assertSee('Hospital settings')
            ->assertSee('Assistant instructions')
            ->assertSee(route('admin.settings.update'), false);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'name' => 'Updated Hospital',
                'address' => 'Central Avenue',
                'phone' => '0700111000',
                'emergency_phone' => '0700222000',
                'email' => 'contact@example.test',
                'website' => 'https://example.test',
                'whatsapp_number' => '254700111000',
                'whatsapp_api_version' => 'v20.0',
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'default_language' => 'sw',
                'supported_languages' => ['en', 'sw'],
                'hours_emergency' => '24/7',
                'hours_outpatient' => 'Weekdays 8:00-17:00',
                'mpesa_shortcode' => '123456',
                'deposit_amount' => 750,
                'slot_duration_minutes' => 45,
                'no_show_grace_minutes' => 20,
                'settings' => [
                    'about' => 'Updated hospital information.',
                    'ai_instructions' => 'Use concise, welcoming language.',
                ],
            ])
            ->assertRedirect(route('admin.settings'));

        $hospital->refresh();
        $this->assertSame('Updated Hospital', $hospital->name);
        $this->assertSame('#123456', $hospital->primary_color);
        $this->assertSame(45, $hospital->slot_duration_minutes);
        $this->assertSame('preserve', $hospital->settings['existing_setting']);
        $this->assertSame('Use concise, welcoming language.', $hospital->settings['ai_instructions']);
    }

    public function test_reports_show_tenant_metrics_and_export_formula_safe_csv(): void
    {
        [$hospital, $admin] = $this->createAdminTenant('reports-admin');
        $this->createAppointment($hospital, '=HYPERLINK("https://attacker.test","patient")');
        $this->createPayment($hospital, 'paid-report-payment', MpesaPayment::STATUS_COMPLETED, 125.5);
        $otherHospital = $this->createHospital('other-reports');
        $this->createAppointment($otherHospital, 'Other tenant patient');
        $this->assertDatabaseHas('appointment_requests', [
            'hospital_id' => $hospital->id,
            'name' => '=HYPERLINK("https://attacker.test","patient")',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.reports.index', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('Daily appointments')
            ->assertSee('125.50')
            ->assertViewHas('appointmentCount', 1);

        $csvResponse = $this->actingAs($admin)
            ->get(route('admin.reports.export', ['from' => today()->toDateString(), 'to' => today()->toDateString()]))
            ->assertDownload('appointment-report-'.today()->toDateString().'-to-'.today()->toDateString().'.csv')
            ->assertStreamed();

        $csv = $csvResponse->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('Other tenant patient', $csv);
    }

    public function test_unauthenticated_users_cannot_open_admin_operations(): void
    {
        $this->get(route('admin.conversations.index'))
            ->assertRedirect(route('login'));
    }

    /**
     * @return array{Hospital, User}
     */
    private function createAdminTenant(string $slug): array
    {
        $hospital = $this->createHospital($slug);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        return [$hospital, $admin];
    }

    private function createHospital(string $slug): Hospital
    {
        return Hospital::query()->create([
            'name' => 'Test Hospital',
            'slug' => $slug,
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
            'deposit_amount' => 500,
            'slot_duration_minutes' => 30,
            'no_show_grace_minutes' => 30,
            'default_language' => 'en',
            'supported_languages' => ['en', 'sw'],
            'hours_emergency' => '24/7',
            'hours_outpatient' => 'Weekdays 8:00-17:00',
            'whatsapp_api_version' => 'v20.0',
        ]);
    }

    private function createConversation(Hospital $hospital, string $sessionId, string $message): Conversation
    {
        return $this->runForHospital($hospital, fn (): Conversation => Conversation::query()->create([
            'session_id' => $sessionId,
            'user_message' => $message,
            'ai_response' => 'A tenant-specific reply.',
            'confidence_score' => 0.9,
            'channel' => 'web',
            'escalated' => false,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createKnowledgeEntry(Hospital $hospital, array $attributes): KnowledgeBase
    {
        return $this->runForHospital($hospital, fn (): KnowledgeBase => KnowledgeBase::query()->create([
            ...$attributes,
            'source' => 'Feature test',
            'last_updated' => today(),
        ]));
    }

    private function createPayment(
        Hospital $hospital,
        string $checkoutRequestId,
        string $status = MpesaPayment::STATUS_PENDING,
        float $amount = 500,
    ): MpesaPayment {
        return $this->runForHospital($hospital, fn (): MpesaPayment => MpesaPayment::query()->create([
            'checkout_request_id' => $checkoutRequestId,
            'merchant_request_id' => 'merchant-'.$checkoutRequestId,
            'phone' => '254700111000',
            'amount' => $amount,
            'account_reference' => 'appointment',
            'transaction_desc' => 'Appointment deposit',
            'status' => $status,
            'processed_at' => $status === MpesaPayment::STATUS_COMPLETED ? now() : null,
        ]));
    }

    private function createAppointment(Hospital $hospital, string $name): AppointmentRequest
    {
        return $this->runForHospital($hospital, fn (): AppointmentRequest => AppointmentRequest::query()->create([
            'session_id' => 'report-session-'.$hospital->id,
            'name' => $name,
            'phone' => '0700000000',
            'preferred_date' => today(),
            'reason' => 'Consultation',
            'raw_message' => 'Appointment request',
            'status' => AppointmentRequest::STATUS_PENDING,
            'payment_status' => 'paid',
        ]));
    }

    private function runForHospital(Hospital $hospital, callable $callback): mixed
    {
        $previousHospital = hospital();
        app()->instance('currentHospital', $hospital);

        try {
            return $callback();
        } finally {
            if ($previousHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }
}
