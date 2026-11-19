<?php

namespace Tests\Feature;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\KnowledgeBase;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DemoHospitalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoHospitalSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_complete_tenant_without_changing_other_hospitals(): void
    {
        app()->forgetInstance('currentHospital');
        $nairobi = Hospital::factory()->create([
            'name' => 'Nairobi Hospital',
            'slug' => 'nairobi-hospital',
            'subscription_plan' => 'enterprise',
        ]);
        $untouchedHospitals = Hospital::withoutGlobalScopes()
            ->whereIn('slug', ['pearl', $nairobi->slug])
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'subscription_plan'])
            ->toArray();

        $this->seed(DemoHospitalSeeder::class);
        $this->seed(DemoHospitalSeeder::class);

        $demo = Hospital::withoutGlobalScopes()->where('slug', 'demo')->firstOrFail();
        $this->assertSame('Demo Hospital', $demo->name);
        $this->assertSame('enterprise', $demo->subscription_plan);
        $this->assertSame(Hospital::BOOKING_PAYMENT_MODE_NONE, $demo->booking_payment_mode);
        $this->assertTrue($demo->reminder_enabled);
        $this->assertSame(24, $demo->reminder_hours_before);
        $this->assertSame('https://g.page/r/demo-hospital/review', data_get($demo->settings, 'google_review_url'));
        $this->assertSame(24, Service::withoutGlobalScope('hospital')->where('hospital_id', $demo->id)->count());
        $this->assertSame(6, User::withoutGlobalScope('hospital')->where('hospital_id', $demo->id)->where('is_doctor', true)->count());
        $this->assertGreaterThanOrEqual(30, KnowledgeBase::withoutGlobalScope('hospital')->where('hospital_id', $demo->id)->count());
        $this->assertSame(10, AppointmentRequest::withoutGlobalScope('hospital')->where('hospital_id', $demo->id)->count());
        $this->assertSame(10, AppointmentRequest::withoutGlobalScope('hospital')->where('hospital_id', $demo->id)->where('status', AppointmentRequest::STATUS_CONFIRMED)->count());
        $this->assertSame($untouchedHospitals, Hospital::withoutGlobalScopes()
            ->whereIn('slug', ['pearl', $nairobi->slug])
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'subscription_plan'])
            ->toArray());
    }
}
