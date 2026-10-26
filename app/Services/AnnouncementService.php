<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AnnouncementService
{
    public function __construct(
        private readonly SmsService $sms,
        private readonly WhatsAppService $whatsApp,
    ) {}

    /**
     * @return Collection<int, AppointmentRequest>
     */
    public function buildRecipients(Announcement $announcement): Collection
    {
        $filter = $announcement->audience_filter ?? [];
        $query = AppointmentRequest::withoutGlobalScope('hospital')
            ->with(['doctor:id,name', 'service:id,name', 'hospital:id,name'])
            ->where('hospital_id', $announcement->hospital_id)
            ->whereIn('status', [
                AppointmentRequest::STATUS_PENDING,
                AppointmentRequest::STATUS_CONFIRMED,
            ])
            ->whereNotNull('phone')
            ->where('phone', '!=', '');

        if ($announcement->type === 'reminder') {
            if (! $announcement->reminder_appointment_id) {
                return new Collection;
            }

            $query->whereKey($announcement->reminder_appointment_id)
                ->where('status', AppointmentRequest::STATUS_CONFIRMED);
        }

        $appointmentDate = $filter['appointment_date'] ?? 'tomorrow';
        if ($appointmentDate === 'tomorrow') {
            $query->whereDate('preferred_date', now()->addDay()->toDateString());
        } elseif ($appointmentDate === 'upcoming') {
            $query->whereDate('preferred_date', '>=', today()->toDateString());
        } elseif (is_string($appointmentDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate) === 1) {
            $query->whereDate('preferred_date', $appointmentDate);
        } else {
            $query->whereRaw('1 = 0');
        }

        if (filled($filter['doctor_id'] ?? null)) {
            $query->where('doctor_id', (int) $filter['doctor_id']);
        }

        if (filled($filter['service_id'] ?? null)) {
            $query->where('service_id', (int) $filter['service_id']);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * @return BaseCollection<int, Announcement>
     */
    public function scheduleReminderAnnouncements(Hospital $hospital): BaseCollection
    {
        $now = now()->toImmutable();
        $hoursBefore = min(168, max(1, (int) ($hospital->reminder_hours_before ?? 24)));
        $dueAppointments = AppointmentRequest::withoutGlobalScope('hospital')
            ->where('hospital_id', $hospital->id)
            ->where('status', AppointmentRequest::STATUS_CONFIRMED)
            ->whereNotNull('slot_start_time')
            ->whereBetween('preferred_date', [
                $now->startOfDay()->toDateTimeString(),
                $now->addHours($hoursBefore)->endOfDay()->toDateTimeString(),
            ])
            ->orderBy('id')
            ->get()
            ->filter(function (AppointmentRequest $appointment) use ($now, $hoursBefore): bool {
                $appointmentAt = CarbonImmutable::parse(
                    $appointment->preferred_date->toDateString().' '.$appointment->slot_start_time,
                );

                return $appointmentAt->isAfter($now)
                    && $appointmentAt->subHours($hoursBefore)->lessThanOrEqualTo($now);
            })
            ->values();

        if ($dueAppointments->isEmpty()) {
            return new BaseCollection;
        }

        $systemUser = $hospital->users()
            ->where(fn ($query) => $query->where('is_admin', true)->orWhere('role', 'hospital_admin'))
            ->orderBy('id')
            ->first();

        if (! $systemUser) {
            throw new RuntimeException('A hospital admin is required to schedule appointment reminders.');
        }

        return $dueAppointments
            ->map(fn (AppointmentRequest $appointment): Announcement => Announcement::query()->firstOrCreate(
                [
                    'hospital_id' => $hospital->id,
                    'type' => 'reminder',
                    'reminder_appointment_id' => $appointment->id,
                ],
                [
                    'sent_by_user_id' => $systemUser->id,
                    'message' => $hospital->reminder_message_template ?: Hospital::DEFAULT_REMINDER_MESSAGE_TEMPLATE,
                    'audience_filter' => ['appointment_date' => $appointment->preferred_date->toDateString()],
                    'channel' => 'sms',
                    'scheduled_for' => $now,
                    'status' => 'scheduled',
                ],
            ))
            ->filter(fn (Announcement $announcement): bool => in_array($announcement->status, ['scheduled', 'sending'], true))
            ->values();
    }

    public function sendAnnouncement(Announcement $announcement): void
    {
        Cache::lock('announcement-send-'.$announcement->id, 120)->block(10, function () use ($announcement): void {
            $lockedAnnouncement = DB::transaction(function () use ($announcement): ?Announcement {
                $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
                if ($locked->status === 'sent'
                    || ($locked->status === 'sending' && $locked->updated_at?->greaterThan(now()->subMinutes(10)))
                ) {
                    return null;
                }

                $locked->forceFill(['status' => 'sending'])->save();

                return $locked;
            });

            if (! $lockedAnnouncement) {
                return;
            }

            $hospital = Hospital::query()->findOrFail($lockedAnnouncement->hospital_id);
            $hadCurrentHospital = app()->bound('currentHospital');
            $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
            app()->instance('currentHospital', $hospital);
            $hasFailures = false;

            try {
                foreach ($this->buildRecipients($lockedAnnouncement) as $appointment) {
                    $recipient = AnnouncementRecipient::query()->firstOrCreate(
                        [
                            'announcement_id' => $lockedAnnouncement->id,
                            'appointment_request_id' => $appointment->id,
                        ],
                        [
                            'recipient_name' => $appointment->name,
                            'recipient_phone' => $appointment->phone,
                            'rendered_message' => $this->renderForAppointment($lockedAnnouncement, $appointment),
                            'delivery_status' => 'queued',
                        ],
                    );

                    if ($recipient->delivery_status === 'sent') {
                        continue;
                    }

                    try {
                        $delivery = $this->deliver(
                            $lockedAnnouncement,
                            $recipient->recipient_phone,
                            $recipient->rendered_message,
                        );
                        $recipient->forceFill([
                            'delivery_status' => $delivery->success ? 'sent' : 'failed',
                            'sent_at' => $delivery->success ? now() : null,
                            'provider_message_id' => $delivery->providerMessageId,
                        ])->save();
                        $hasFailures = $hasFailures || ! $delivery->success;
                    } catch (Throwable $exception) {
                        $recipient->forceFill(['delivery_status' => 'failed'])->save();
                        $hasFailures = true;
                        Log::error('Announcement recipient delivery failed.', [
                            'announcement_id' => $lockedAnnouncement->id,
                            'hospital_id' => $lockedAnnouncement->hospital_id,
                            'recipient_id' => $recipient->id,
                            'exception' => $exception,
                        ]);
                    }
                }
            } finally {
                if ($hadCurrentHospital) {
                    app()->instance('currentHospital', $previousHospital);
                } else {
                    app()->forgetInstance('currentHospital');
                }
            }

            $recipientCount = $lockedAnnouncement->recipients()->count();
            $lockedAnnouncement->forceFill([
                'recipients_count' => $recipientCount,
                'sent_at' => now(),
                'status' => $hasFailures || $recipientCount === 0 ? 'failed' : 'sent',
            ])->save();
        });
    }

    /**
     * @return array{processed: int, failed: int}
     */
    public function sendDueAnnouncements(): array
    {
        $result = ['processed' => 0, 'failed' => 0];
        Announcement::query()
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($announcements) use (&$result): void {
                foreach ($announcements as $announcement) {
                    $this->sendAnnouncement($announcement);
                    $announcement->refresh();
                    $result['processed']++;
                    $result['failed'] += $announcement->status === 'failed' ? 1 : 0;
                }
            });

        return $result;
    }

    private function renderForAppointment(Announcement $announcement, AppointmentRequest $appointment): string
    {
        $date = $appointment->preferred_date?->format('d M Y') ?? 'Not provided';
        $time = filled($appointment->slot_start_time)
            ? CarbonImmutable::parse($appointment->slot_start_time)->format('g:i A')
            : 'Not provided';

        return $announcement->renderFor(
            $appointment->name,
            $appointment->doctor?->name ?? 'the hospital team',
            trim($date.' '.$time),
            [
                'booking_reference' => $appointment->booking_reference ?: 'Not available',
                'service_name' => $appointment->service?->name ?? $appointment->reason,
                'hospital_name' => $appointment->hospital?->name ?? '',
            ],
        );
    }

    private function deliver(Announcement $announcement, string $phone, string $message): SmsResult
    {
        $smsResult = null;
        if (in_array($announcement->channel, ['sms', 'both'], true)) {
            $smsResult = $this->sms->send($phone, $message, app()->isProduction() ? 'configured' : 'log');
        }

        $whatsAppResult = null;
        if (in_array($announcement->channel, ['whatsapp', 'both'], true)) {
            if ($announcement->channel === 'both' && ! $this->whatsApp->isConfigured()) {
                Log::info('WhatsApp not configured; announcement was delivered via SMS only.', [
                    'announcement_id' => $announcement->id,
                    'hospital_id' => $announcement->hospital_id,
                ]);
                $whatsAppResult = ['success' => true];
            } else {
                $whatsAppResult = $this->whatsApp->sendMessage($phone, $message);
            }
        }

        $success = ($smsResult?->success ?? true)
            && ($whatsAppResult['success'] ?? true);

        return new SmsResult(
            $success,
            $success ? 'sent' : 'failed',
            message: $success ? 'Accepted for delivery.' : 'At least one selected channel failed.',
        );
    }
}
