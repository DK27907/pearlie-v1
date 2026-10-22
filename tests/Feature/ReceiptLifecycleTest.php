<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\Receipt;
use App\Models\User;
use App\Services\EtimsService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceiptLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_mark_a_tenant_appointment_paid_and_download_its_receipt(): void
    {
        Storage::fake();
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'payment_status' => 'pending',
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'payment_due_at' => 'at_hospital',
        ]);

        $response = $this->actingAs($admin)->post(
            route('admin.appointments.mark-paid', $appointment->id),
            ['amount' => '1.00', 'payment_method' => 'cash'],
        );

        $response->assertRedirect(route('admin.appointments.show', $appointment->id));
        $appointment->refresh();
        $receipt = Receipt::query()->where('appointment_request_id', $appointment->id)->firstOrFail();

        $this->assertSame('paid', $appointment->payment_status);
        $this->assertSame('cash', $appointment->payment_method);
        $this->assertSame((int) $admin->id, $appointment->paid_by_user_id);
        $this->assertSame('RCPT-2026-000001', $receipt->receipt_number);
        $this->assertSame('cash', $receipt->payment_method);
        Storage::assertExists($receipt->pdf_path);

        $this->get($receipt->signedDownloadUrl())->assertOk();
    }

    public function test_receipt_download_rejects_unsigned_requests(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
        ]);
        $receipt = app(ReceiptService::class)->generateForAppointment($appointment);

        $this->get(route('receipts.download', $receipt->id))->assertForbidden();
    }

    public function test_cashier_cannot_mark_a_partial_or_excess_amount_as_paid(): void
    {
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'payment_status' => 'pending',
            'payment_amount' => 100,
            'payment_method' => 'cash',
            'payment_due_at' => 'at_hospital',
        ]);

        foreach (['50.00', '150.00'] as $amount) {
            $this->actingAs($admin)
                ->from(route('admin.appointments.show', $appointment->id))
                ->post(route('admin.appointments.mark-paid', $appointment->id), [
                    'amount' => $amount,
                    'payment_method' => 'cash',
                ])
                ->assertRedirect(route('admin.appointments.show', $appointment->id))
                ->assertSessionHasErrors('amount');
        }

        $this->assertSame('pending', $appointment->fresh()->payment_status);
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_receipt_generation_is_idempotent_for_an_appointment(): void
    {
        Storage::fake();
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'payment_status' => 'paid',
            'payment_amount' => 12.5,
        ]);

        $firstReceipt = app(ReceiptService::class)->generateForAppointment($appointment);
        $secondReceipt = app(ReceiptService::class)->generateForAppointment($appointment);

        $this->assertSame($firstReceipt->id, $secondReceipt->id);
        $this->assertDatabaseCount('receipts', 1);
    }

    public function test_receipt_uses_tenant_branding_and_escapes_patient_content(): void
    {
        Storage::fake();
        $hospital = Hospital::factory()->create([
            'name' => 'Clinic <script>alert(1)</script>',
            'receipt_footer_text' => 'Thank <strong>you</strong>.',
            'etims_enabled' => false,
        ]);
        app()->instance('currentHospital', $hospital);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Patient <script>alert(2)</script>',
        ]);

        $receipt = app(ReceiptService::class)->generateForAppointment($appointment);
        $html = view('receipts.appointment', [
            'receipt' => $receipt,
            'hospital' => $hospital,
            'appointment' => $appointment,
            'mpesa' => null,
            'letterheadDataUri' => null,
            'footerDataUri' => null,
            'signatureDataUri' => null,
        ])->render();

        $this->assertSame('not_applicable', $receipt->etims_status);
        $this->assertStringContainsString('Patient &lt;script&gt;alert(2)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(2)</script>', $html);
        $this->assertStringContainsString('Thank &lt;strong&gt;you&lt;/strong&gt;.', $html);
        $this->assertStringNotContainsString('eTIMS invoice', $html);
    }

    public function test_etims_receipt_is_marked_pending_without_calling_an_external_api(): void
    {
        Storage::fake();
        $hospital = Hospital::factory()->create(['etims_enabled' => true]);
        app()->instance('currentHospital', $hospital);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'payment_status' => 'paid',
            'payment_amount' => 15,
        ]);

        $receipt = app(ReceiptService::class)->generateForAppointment($appointment);
        $result = app(EtimsService::class)->submitReceipt($receipt);

        $this->assertSame('pending', $result->status);
        $this->assertSame('pending', $receipt->fresh()->etims_status);
        $this->assertNull($receipt->fresh()->etims_invoice_number);
        $this->assertNull($receipt->fresh()->etims_qr_data);
    }

    public function test_receipt_settings_upload_is_scoped_to_the_current_hospital(): void
    {
        Storage::fake();
        $hospital = Hospital::factory()->create([
            'subscription_plan' => 'enterprise',
            'subscription_status' => 'active',
        ]);
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);

        $response = $this->actingAs($admin)->put(
            route('admin.integration-settings.receipts.update'),
            [
                'receipt_prefix' => 'NAIROBI',
                'receipt_title' => 'Payment Receipt',
                'receipt_footer_text' => 'Thank you for choosing us.',
                'letterhead' => UploadedFile::fake()->createWithContent(
                    'letterhead.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jSl0AAAAASUVORK5CYII='),
                ),
            ],
        );

        $response->assertRedirect(route('admin.integration-settings.edit', ['tab' => 'receipts']));
        $hospital->refresh();
        $this->assertSame('NAIROBI', $hospital->receipt_prefix);
        $this->assertSame('Thank you for choosing us.', $hospital->receipt_footer_text);
        Storage::assertExists($hospital->receipt_letterhead_path);
    }
}
