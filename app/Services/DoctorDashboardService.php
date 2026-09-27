<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Escalation;
use App\Models\DoctorUnavailableDate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DoctorDashboardService
{
    /**
     * @return array{today_count: int, week_count: int, upcoming_count: int, escalation_count: int, recent_appointments: Collection, today_appointments: Collection}
     */
    public function dashboardData(User $doctor): array
    {
        try {
            $today = CarbonImmutable::today();
            $weekStart = $today->startOfWeek();
            $weekEnd = $today->endOfWeek();
            $appointments = $doctor->doctorAppointments();

            return [
                'today_count' => (clone $appointments)->whereDate('preferred_date', $today)->count(),
                'week_count' => (clone $appointments)->whereBetween('preferred_date', [$weekStart, $weekEnd])->count(),
                'upcoming_count' => (clone $appointments)
                    ->whereDate('preferred_date', '>=', $today)
                    ->whereIn('status', [AppointmentRequest::STATUS_PENDING, AppointmentRequest::STATUS_CONFIRMED])
                    ->count(),
                'escalation_count' => Escalation::query()
                    ->whereIn('status', [Escalation::STATUS_PENDING, Escalation::STATUS_IN_PROGRESS])
                    ->count(),
                'recent_appointments' => (clone $appointments)
                    ->with('doctor')
                    ->orderByDesc('preferred_date')
                    ->orderByDesc('created_at')
                    ->limit(8)
                    ->get(),
                'today_appointments' => (clone $appointments)
                    ->whereDate('preferred_date', $today)
                    ->orderBy('slot_start_time')
                    ->orderBy('id')
                    ->get(),
            ];
        } catch (Throwable $exception) {
            Log::error('Unable to load the doctor dashboard.', [
                'doctor_id' => $doctor->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array{date?: string|null, status?: string|null}  $filters
     */
    public function appointments(User $doctor, array $filters): LengthAwarePaginator
    {
        try {
            return $doctor->doctorAppointments()
                ->when($filters['date'] ?? null, fn ($query, $date) => $query->whereDate('preferred_date', $date))
                ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->orderByDesc('preferred_date')
                ->orderByDesc('id')
                ->paginate(15)
                ->withQueryString();
        } catch (Throwable $exception) {
            Log::error('Unable to list doctor appointments.', [
                'doctor_id' => $doctor->id,
                'filters' => $filters,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array{availability: Collection, unavailable_dates: Collection}
     */
    public function availabilitySettings(User $doctor): array
    {
        try {
            return [
                'availability' => $doctor->availabilities()->orderBy('day_of_week')->get()->keyBy('day_of_week'),
                'unavailable_dates' => $doctor->unavailableDates()->orderBy('date')->get(),
            ];
        } catch (Throwable $exception) {
            Log::error('Unable to load doctor availability settings.', [
                'doctor_id' => $doctor->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function ownedAppointmentOrFail(User $doctor, int $appointmentId): AppointmentRequest
    {
        try {
            return $doctor->doctorAppointments()->findOrFail($appointmentId);
        } catch (Throwable $exception) {
            Log::error('Unable to find an appointment owned by the doctor.', [
                'doctor_id' => $doctor->id,
                'appointment_id' => $appointmentId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function confirmAppointment(User $doctor, int $appointmentId): AppointmentRequest
    {
        try {
            return DB::transaction(function () use ($doctor, $appointmentId): AppointmentRequest {
                $appointment = $doctor->doctorAppointments()
                    ->whereKey($appointmentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless($appointment->status === AppointmentRequest::STATUS_PENDING, 422, 'Only pending appointments can be confirmed.');

                $appointment->update([
                    'status' => AppointmentRequest::STATUS_CONFIRMED,
                    'confirmed_by_doctor_at' => now(),
                ]);

                return $appointment->refresh();
            });
        } catch (Throwable $exception) {
            Log::error('Unable to confirm a doctor appointment.', [
                'doctor_id' => $doctor->id,
                'appointment_id' => $appointmentId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function completeAppointment(User $doctor, int $appointmentId): AppointmentRequest
    {
        try {
            return DB::transaction(function () use ($doctor, $appointmentId): AppointmentRequest {
                $appointment = $doctor->doctorAppointments()
                    ->whereKey($appointmentId)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_unless($appointment->status === AppointmentRequest::STATUS_CONFIRMED, 422, 'Only confirmed appointments can be completed.');

                $appointment->update(['status' => AppointmentRequest::STATUS_COMPLETED]);

                return $appointment->refresh();
            });
        } catch (Throwable $exception) {
            Log::error('Unable to complete a doctor appointment.', [
                'doctor_id' => $doctor->id,
                'appointment_id' => $appointmentId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array<int, array{day_of_week: int, start_time: string, end_time: string, slot_duration_minutes: int, max_patients_per_slot: int, is_active?: bool}>  $availability
     */
    public function updateAvailability(User $doctor, array $availability): void
    {
        try {
            DB::transaction(function () use ($doctor, $availability): void {
                $doctor->availabilities()->delete();

                foreach ($availability as $day) {
                    $doctor->availabilities()->create([
                        ...$day,
                        'is_active' => filter_var($day['is_active'] ?? false, FILTER_VALIDATE_BOOL),
                    ]);
                }
            });
        } catch (Throwable $exception) {
            Log::error('Unable to update doctor availability.', [
                'doctor_id' => $doctor->id,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function markUnavailable(User $doctor, string $date, ?string $reason): DoctorUnavailableDate
    {
        try {
            return DoctorUnavailableDate::query()->updateOrCreate(
                ['doctor_id' => $doctor->id, 'date' => $date],
                ['reason' => $reason],
            );
        } catch (Throwable $exception) {
            Log::error('Unable to mark a doctor unavailable.', [
                'doctor_id' => $doctor->id,
                'date' => $date,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function removeUnavailable(User $doctor, int $unavailableDateId): void
    {
        try {
            $doctor->unavailableDates()->findOrFail($unavailableDateId)->delete();
        } catch (Throwable $exception) {
            Log::error('Unable to remove a doctor unavailable date.', [
                'doctor_id' => $doctor->id,
                'unavailable_date_id' => $unavailableDateId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
