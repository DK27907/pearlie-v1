<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentNotification;
use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\Hospital;
use App\Models\KnowledgeBase;
use App\Models\MpesaCallbackInbox;
use App\Models\MpesaPayment;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\NairobiHospitalDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NairobiHospitalDemoBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NairobiHospitalDemoSeeder::class);
    }

    public function test_dental_booking_with_test_phone_completes_from_stk_push_to_receipt(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00'));
        Http::preventStrayRequests();
        Http::fake([
            '*/oauth/v1/generate*' => Http::response([
                'access_token' => 'fake-token',
                'expires_in' => 3600,
            ]),
            '*/mpesa/stkpush/v1/processrequest' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'ws_CO_NAIROBI_254748249882',
                'MerchantRequestID' => 'mr-nairobi',
                'CustomerMessage' => 'Success. Request accepted for processing',
            ]),
            '*/mpesa/stkpushquery/v1/query' => Http::response([
                'ResponseCode' => '0',
                'CheckoutRequestID' => 'ws_CO_NAIROBI_254748249882',
                'ResultCode' => '0',
                'ResultDesc' => 'The service request is processed successfully.',
            ]),
        ]);
        Queue::fake([SendPaymentNotification::class]);

        $hospital = Hospital::withoutGlobalScopes()
            ->where('slug', 'nairobi-hospital')
            ->firstOrFail();
        $service = Service::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('name', 'Dental Checkup')
            ->firstOrFail();
        $hospital->forceFill([
            'mpesa_consumer_key' => 'fake-key',
            'mpesa_consumer_secret' => 'fake-secret',
            'mpesa_shortcode' => '174379',
            'mpesa_passkey' => 'fake-passkey',
        ])->save();

        $responses = [];
        foreach ([
            'I want to book an appointment',
            'Dental Checkup',
            'tomorrow',
            'Demo Test Patient',
            '254748249882',
            '10:30 AM',
            'yes',
            'Pay now',
        ] as $message) {
            $responses[] = $this->postJson('/h/'.$hospital->slug.'/chat', [
                'message' => $message,
            ])->assertOk();
        }

        $responses[0]->assertJsonPath('state', 'COLLECTING');
        $responses[1]->assertJsonPath('collected_fields.service_id', $service->id);
        $responses[5]->assertJsonPath('collected_fields.patient_phone', '254748249882');
        $this->assertStringContainsString('KSh 1.00', $responses[5]->json('response'));
        $responses[6]->assertJsonPath('state', 'PAYMENT_CHOICE');
        $this->assertStringContainsString('We sent an M-Pesa prompt to 254748249882', $responses[7]->json('response'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/mpesa/stkpush/v1/processrequest')
            && $request['Amount'] === 1
            && $request['PhoneNumber'] === '254748249882');

        $payment = MpesaPayment::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('phone', '254748249882')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame(1.0, (float) $payment->amount);
        $this->assertSame('ws_CO_NAIROBI_254748249882', $payment->checkout_request_id);

        $this->postJson('/api/mpesa/callback/'.$hospital->slug, [
            'Body' => [
                'stkCallback' => [
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NAIROBI1SHILLING'],
                            ['Name' => 'Amount', 'Value' => 1],
                            ['Name' => 'PhoneNumber', 'Value' => 254748249882],
                        ],
                    ],
                ],
            ],
        ])->assertOk()->assertExactJson([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);

        $payment->refresh();
        $this->assertSame(MpesaPayment::STATUS_COMPLETED, $payment->status);
        $this->assertSame('NAIROBI1SHILLING', $payment->mpesa_receipt);
        $this->assertDatabaseHas('mpesa_callback_inbox', [
            'checkout_request_id' => $payment->checkout_request_id,
            'status' => MpesaCallbackInbox::STATUS_PROCESSED,
        ]);

        $appointment = AppointmentRequest::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->where('phone', '254748249882')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($payment->appointment_request_id, $appointment->id);
        $this->assertSame('paid', $appointment->payment_status);
        $this->assertSame('NAIROBI1SHILLING', $appointment->mpesa_receipt);

        $admin = User::withoutGlobalScopes()
            ->where('email', 'admin@nairobi-hospital.test')
            ->firstOrFail();
        $admin->forceFill(['must_change_password' => false])->save();

        $this->actingAs($admin)
            ->get(route('admin.appointments.show', $appointment->id))
            ->assertOk()
            ->assertSee('NAIROBI1SHILLING');
    }

    public function test_seeder_keeps_tenant_service_doctor_and_knowledge_counts_idempotent(): void
    {
        $hospital = Hospital::withoutGlobalScopes()
            ->where('slug', 'nairobi-hospital')
            ->firstOrFail();
        $before = [
            'services' => Service::withoutGlobalScopes()->where('hospital_id', $hospital->id)->count(),
            'doctors' => User::withoutGlobalScopes()
                ->where('hospital_id', $hospital->id)
                ->where('is_doctor', true)
                ->count(),
            'availability' => DoctorAvailability::withoutGlobalScopes()
                ->where('hospital_id', $hospital->id)
                ->count(),
            'knowledge' => KnowledgeBase::withoutGlobalScopes()->where('hospital_id', $hospital->id)->count(),
        ];

        $this->seed(NairobiHospitalDemoSeeder::class);

        $this->assertSame(11, $before['services']);
        $this->assertSame(6, $before['doctors']);
        $this->assertSame(30, $before['availability']);
        $this->assertSame(25, $before['knowledge']);
        $this->assertSame($before['services'], Service::withoutGlobalScopes()->where('hospital_id', $hospital->id)->count());
        $this->assertSame(
            $before['doctors'],
            User::withoutGlobalScopes()->where('hospital_id', $hospital->id)->where('is_doctor', true)->count(),
        );
        $this->assertSame(
            $before['availability'],
            DoctorAvailability::withoutGlobalScopes()->where('hospital_id', $hospital->id)->count(),
        );
        $this->assertSame($before['knowledge'], KnowledgeBase::withoutGlobalScopes()->where('hospital_id', $hospital->id)->count());
    }
}
