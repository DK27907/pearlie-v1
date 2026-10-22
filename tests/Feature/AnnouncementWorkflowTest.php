<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use App\Services\AnnouncementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnnouncementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_renders_patient_and_appointment_placeholders_and_records_delivery(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00'));
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $doctor = User::factory()->for($hospital, 'hospital')->create([
            'name' => 'Dr. Amina Njeri',
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        $service = Service::query()->create([
            'hospital_id' => $hospital->id,
            'name' => 'Dental Checkup',
            'price' => 1,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'name' => 'Test Patient',
            'phone' => '254748249882',
            'preferred_date' => today()->addDay(),
            'slot_start_time' => '10:30:00',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $announcement = Announcement::query()->create([
            'hospital_id' => $hospital->id,
            'sent_by_user_id' => User::factory()->for($hospital, 'hospital')->create([
                'is_admin' => true,
                'role' => 'hospital_admin',
            ])->id,
            'type' => 'announcement',
            'message' => 'Hello {patient_name}, your {doctor_name} appointment is {appointment_time}.',
            'audience_filter' => ['appointment_date' => now()->addDay()->toDateString()],
            'channel' => 'sms',
            'status' => 'draft',
        ]);

        $recipients = app(AnnouncementService::class)->buildRecipients($announcement);

        $this->assertCount(1, $recipients);
        $this->assertSame(
            'Hello Test Patient, your Dr. Amina Njeri appointment is '.today()->addDay()->format('d M Y').' 10:30 AM.',
            $announcement->renderFor('Test Patient', 'Dr. Amina Njeri', today()->addDay()->format('d M Y').' 10:30 AM'),
        );
        $this->assertSame((int) $appointment->id, (int) $recipients->first()->id);

        app(AnnouncementService::class)->sendAnnouncement($announcement);
        $announcement->refresh();

        $this->assertSame('sent', $announcement->status);
        $this->assertSame(1, $announcement->recipients_count);
        $this->assertDatabaseHas('announcement_recipients', [
            'announcement_id' => $announcement->id,
            'appointment_request_id' => $appointment->id,
            'delivery_status' => 'sent',
        ]);
    }

    public function test_announcement_build_recipients_never_includes_another_hospital(): void
    {
        $hospital = Hospital::factory()->create();
        $otherHospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'preferred_date' => today()->addDay(),
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        app()->instance('currentHospital', $otherHospital);
        AppointmentRequest::factory()->create([
            'hospital_id' => $otherHospital->id,
            'preferred_date' => today()->addDay(),
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        app()->instance('currentHospital', $hospital);
        $announcement = Announcement::query()->create([
            'hospital_id' => $hospital->id,
            'sent_by_user_id' => $admin->id,
            'type' => 'announcement',
            'message' => 'Reminder for {patient_name}',
            'audience_filter' => ['appointment_date' => 'tomorrow'],
            'channel' => 'sms',
            'status' => 'draft',
        ]);

        $recipients = app(AnnouncementService::class)->buildRecipients($announcement);

        $this->assertCount(1, $recipients);
        $this->assertSame((int) $hospital->id, (int) $recipients->first()->hospital_id);
    }

    public function test_announcement_details_are_scoped_to_the_current_hospital(): void
    {
        $hospital = Hospital::factory()->create();
        $otherHospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        app()->instance('currentHospital', $otherHospital);
        $otherAdmin = User::factory()->for($otherHospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $announcement = Announcement::query()->create([
            'hospital_id' => $otherHospital->id,
            'sent_by_user_id' => $otherAdmin->id,
            'type' => 'announcement',
            'message' => 'Private hospital announcement',
            'audience_filter' => ['appointment_date' => 'tomorrow'],
            'channel' => 'sms',
            'status' => 'sent',
        ]);
        app()->instance('currentHospital', $hospital);

        $this->actingAs($admin)
            ->get(route('admin.announcements.show', $announcement->id))
            ->assertNotFound();
    }

    public function test_hospital_admin_can_send_an_announcement_to_tomorrows_patients(): void
    {
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        $appointment = AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'preferred_date' => today()->addDay(),
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.announcements.store'), [
            'message' => 'Hello {patient_name}, your appointment is tomorrow.',
            'audience' => 'tomorrow',
            'channel' => 'sms',
            'send_option' => 'now',
        ]);
        $announcement = Announcement::query()->firstOrFail();

        $response->assertRedirect(route('admin.announcements.show', $announcement->id));
        $this->assertSame('sent', $announcement->fresh()->status);
        $this->assertDatabaseHas('announcement_recipients', [
            'announcement_id' => $announcement->id,
            'appointment_request_id' => $appointment->id,
            'delivery_status' => 'sent',
        ]);
    }

    public function test_due_scheduled_announcements_send_without_sending_future_announcements(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00'));
        $hospital = Hospital::factory()->create();
        app()->instance('currentHospital', $hospital);
        $admin = User::factory()->for($hospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        AppointmentRequest::factory()->create([
            'hospital_id' => $hospital->id,
            'preferred_date' => today()->addDay(),
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        $dueAnnouncement = Announcement::query()->create([
            'hospital_id' => $hospital->id,
            'sent_by_user_id' => $admin->id,
            'type' => 'announcement',
            'message' => 'Hello {patient_name}',
            'audience_filter' => ['appointment_date' => now()->addDay()->toDateString()],
            'channel' => 'sms',
            'scheduled_for' => now()->subMinute(),
            'status' => 'scheduled',
        ]);
        $futureAnnouncement = Announcement::query()->create([
            'hospital_id' => $hospital->id,
            'sent_by_user_id' => $admin->id,
            'type' => 'announcement',
            'message' => 'Hello {patient_name}',
            'audience_filter' => ['appointment_date' => now()->addDay()->toDateString()],
            'channel' => 'sms',
            'scheduled_for' => now()->addHour(),
            'status' => 'scheduled',
        ]);

        $result = app(AnnouncementService::class)->sendDueAnnouncements();

        $this->assertSame(['processed' => 1, 'failed' => 0], $result);
        $this->assertSame('sent', $dueAnnouncement->fresh()->status);
        $this->assertSame('scheduled', $futureAnnouncement->fresh()->status);
    }

    public function test_reminder_command_only_processes_hospitals_with_reminders_enabled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:00:00'));
        $enabledHospital = Hospital::factory()->create([
            'reminder_enabled' => true,
        ]);
        $disabledHospital = Hospital::factory()->create([
            'reminder_enabled' => false,
        ]);
        app()->instance('currentHospital', $enabledHospital);
        User::factory()->for($enabledHospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        User::factory()->for($enabledHospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);

        AppointmentRequest::factory()->create([
            'hospital_id' => $enabledHospital->id,
            'preferred_date' => today()->addDay(),
            'slot_start_time' => '08:00:00',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);
        AppointmentRequest::factory()->create([
            'hospital_id' => $enabledHospital->id,
            'preferred_date' => today()->addDay(),
            'slot_start_time' => '08:03:00',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);

        app()->instance('currentHospital', $disabledHospital);
        User::factory()->for($disabledHospital, 'hospital')->create([
            'is_admin' => true,
            'role' => 'hospital_admin',
        ]);
        User::factory()->for($disabledHospital, 'hospital')->create([
            'is_doctor' => true,
            'role' => 'doctor',
        ]);
        AppointmentRequest::factory()->create([
            'hospital_id' => $disabledHospital->id,
            'preferred_date' => today()->addDay(),
            'slot_start_time' => '08:00:00',
            'status' => AppointmentRequest::STATUS_CONFIRMED,
        ]);

        Artisan::call('appointments:send-reminders');
        Artisan::call('appointments:send-reminders');

        $this->assertDatabaseHas('announcements', [
            'hospital_id' => $enabledHospital->id,
            'type' => 'reminder',
            'reminder_appointment_id' => AppointmentRequest::withoutGlobalScope('hospital')
                ->where('hospital_id', $enabledHospital->id)
                ->where('slot_start_time', '08:00:00')
                ->value('id'),
        ]);
        $this->assertDatabaseCount('announcements', 1);
        $this->assertDatabaseMissing('announcements', [
            'hospital_id' => $disabledHospital->id,
            'type' => 'reminder',
        ]);
    }

    public function test_migration_repairs_missing_indexes_on_existing_announcement_tables(): void
    {
        Schema::table('announcement_recipients', function (Blueprint $table): void {
            $table->dropUnique('ann_recipient_appointment_unique');
            $table->dropIndex('ann_recipient_delivery_status_idx');
        });

        $migration = require database_path('migrations/2026_10_22_211329_create_announcements_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasIndex(
            'announcement_recipients',
            ['announcement_id', 'appointment_request_id'],
            'unique',
        ));
        $this->assertTrue(Schema::hasIndex(
            'announcement_recipients',
            ['announcement_id', 'delivery_status'],
        ));
        $this->assertContains(
            'ann_recipient_appointment_unique',
            Schema::getIndexListing('announcement_recipients'),
        );
        $this->assertContains(
            'ann_recipient_delivery_status_idx',
            Schema::getIndexListing('announcement_recipients'),
        );
    }
}
