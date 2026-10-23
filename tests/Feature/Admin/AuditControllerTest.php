<?php

namespace Tests\Feature\Admin;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\AppointmentHistory;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\MpesaPayment;
use App\Models\NotificationLog;
use App\Models\PaymentEvent;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_audit_trail_requires_hospital_administrator_access(): void
    {
        [$hospital] = $this->createTenant('audit-access');
        $patient = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'patient',
        ]);

        $this->get(route('admin.audit.index'))
            ->assertRedirect(route('login'));

        $this->actingAs($patient)
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }

    public function test_invalid_appointment_filter_is_rejected(): void
    {
        [, $admin] = $this->createTenant('audit-invalid-filter');

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['appointment_id' => 0]))
            ->assertRedirect()
            ->assertSessionHasErrors('appointment_id');
    }

    public function test_audit_trail_shows_only_tenant_records_and_hides_payment_payloads(): void
    {
        [$hospital, $admin] = $this->createTenant('audit-owner');
        [$otherHospital] = $this->createTenant('audit-other');
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Audit Patient',
        ]);
        $otherAppointment = AppointmentRequest::factory()->create([
            'hospital_id' => $otherHospital->id,
            'name' => 'Foreign Audit Patient',
        ]);

        AppointmentHistory::query()->create([
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'actor_id' => $admin->id,
            'actor_role' => 'hospital_admin',
            'action' => 'rescheduled',
        ]);
        AppointmentHistory::query()->create([
            'hospital_id' => $otherHospital->id,
            'appointment_request_id' => $otherAppointment->id,
            'actor_role' => 'hospital_admin',
            'action' => 'foreign_audit_marker',
        ]);

        $payment = MpesaPayment::factory()
            ->for($appointment, 'appointment')
            ->create([
                'hospital_id' => $hospital->id,
                'status' => MpesaPayment::STATUS_COMPLETED,
                'mpesa_receipt' => 'OWN-MPESA-REF',
            ]);
        PaymentEvent::query()->create([
            'hospital_id' => $hospital->id,
            'payment_id' => $payment->id,
            'event' => 'payment_completed',
            'payload' => ['private_marker' => 'must-not-render'],
        ]);
        Receipt::query()->create([
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'mpesa_payment_id' => $payment->id,
            'receipt_number' => 'RCT-AUDIT-OWN',
            'amount' => 1,
            'payment_method' => 'mpesa',
            'issued_at' => now(),
            'issued_by_user_id' => $admin->id,
        ]);
        NotificationLog::factory()->create([
            'hospital_id' => $hospital->id,
            'appointment_request_id' => $appointment->id,
            'channel' => 'sms',
            'provider' => 'audit-provider',
            'status' => 'sent',
        ]);
        $announcement = Announcement::query()->create([
            'hospital_id' => $hospital->id,
            'sent_by_user_id' => $admin->id,
            'type' => 'reminder',
            'message' => 'Appointment reminder',
            'channel' => 'sms',
            'sent_at' => now(),
            'recipients_count' => 1,
            'status' => 'sent',
        ]);
        AnnouncementRecipient::query()->create([
            'announcement_id' => $announcement->id,
            'appointment_request_id' => $appointment->id,
            'recipient_name' => 'Audit Patient',
            'recipient_phone' => '254700000001',
            'rendered_message' => 'Private reminder content',
            'sent_at' => now(),
            'delivery_status' => 'sent',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.audit.index'))
            ->assertOk()
            ->assertSee('Audit trail')
            ->assertSee('Audit Patient')
            ->assertSee('rescheduled')
            ->assertSee('OWN-MPESA-REF')
            ->assertSee('payment completed')
            ->assertSee('RCT-AUDIT-OWN')
            ->assertSee('audit-provider')
            ->assertDontSee('Foreign Audit Patient')
            ->assertDontSee('foreign_audit_marker')
            ->assertDontSee('must-not-render')
            ->assertDontSee('Private reminder content');

        $this->actingAs($admin)
            ->get(route('admin.audit.index', ['appointment_id' => $otherAppointment->id]))
            ->assertNotFound();
    }

    /**
     * @return array{Hospital, User}
     */
    private function createTenant(string $slug): array
    {
        $hospital = Hospital::factory()->create([
            'slug' => $slug,
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
            'is_active' => true,
        ]);
        $admin = User::factory()->create([
            'hospital_id' => $hospital->id,
            'role' => 'hospital_admin',
        ]);

        return [$hospital, $admin];
    }
}
