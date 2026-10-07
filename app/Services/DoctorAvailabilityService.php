<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\DoctorAvailability;
use App\Models\DoctorUnavailableDate;
use App\Models\Service;
use App\Models\SlotHold;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class DoctorAvailabilityService
{
    /**
     * @return array<string, bool>
     */
    public function getAvailableSlots(int $doctorId, string $date): array
    {
        try {
            if (! User::query()->whereKey($doctorId)->where('is_doctor', true)->exists()) {
                return [];
            }

            $requestedDate = CarbonImmutable::parse($date);
            $availabilities = DoctorAvailability::query()
                ->where('doctor_id', $doctorId)
                ->where('day_of_week', $requestedDate->dayOfWeek)
                ->where('is_active', true)
                ->orderBy('start_time')
                ->get();

            $bookedCounts = AppointmentRequest::query()
                ->where('doctor_id', $doctorId)
                ->whereDate('preferred_date', $requestedDate->toDateString())
                ->whereNotNull('slot_start_time')
                ->whereIn('status', [
                    AppointmentRequest::STATUS_PENDING,
                    AppointmentRequest::STATUS_CONFIRMED,
                ])
                ->select('slot_start_time')
                ->selectRaw('COUNT(*) as booked_count')
                ->groupBy('slot_start_time')
                ->get()
                ->mapWithKeys(fn (AppointmentRequest $appointment): array => [
                    CarbonImmutable::parse($appointment->slot_start_time)->format('H:i') => (int) $appointment->booked_count,
                ]);

            $heldCounts = SlotHold::query()
                ->active()
                ->where('doctor_id', $doctorId)
                ->whereNull('appointment_request_id')
                ->whereBetween('slot_start_at', [
                    $requestedDate->toDateString().' 00:00:00',
                    $requestedDate->toDateString().' 23:59:59',
                ])
                ->pluck('slot_start_at')
                ->map(fn (string $slotStart): string => CarbonImmutable::parse($slotStart)->format('H:i'))
                ->countBy();

            $slots = [];
            $isUnavailable = $this->dateIsUnavailable($doctorId, $requestedDate);

            foreach ($availabilities as $availability) {
                foreach ($availability->generateSlots() as $slot) {
                    $isInPast = $requestedDate->isToday()
                        && CarbonImmutable::parse($slot['start'])->lessThanOrEqualTo(CarbonImmutable::now());

                    $slots[$slot['start']] = ! $isUnavailable
                        && ! $isInPast
                        && ($bookedCounts[$slot['start']] ?? 0) + ($heldCounts[$slot['start']] ?? 0)
                            < $availability->max_patients_per_slot;
                }
            }

            return $slots;
        } catch (Throwable $exception) {
            Log::error('Unable to get doctor availability slots.', [
                'doctor_id' => $doctorId,
                'date' => $date,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function findDoctorForSlot(string $date, string $time, ?int $preferredDoctorId = null): ?User
    {
        try {
            $normalizedTime = CarbonImmutable::parse($time)->format('H:i');
            $doctors = User::query()
                ->where('is_doctor', true)
                ->when(
                    $preferredDoctorId !== null,
                    function ($query) use ($preferredDoctorId): void {
                        $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferredDoctorId]);
                    },
                )
                ->orderBy('id')
                ->get();

            foreach ($doctors as $doctor) {
                if (($this->getAvailableSlots($doctor->id, $date)[$normalizedTime] ?? false) === true) {
                    return $doctor;
                }
            }

            return null;
        } catch (Throwable $exception) {
            Log::error('Unable to find a doctor for the requested slot.', [
                'date' => $date,
                'time' => $time,
                'preferred_doctor_id' => $preferredDoctorId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<int, array{doctor: User, slots: array<string, bool>}>
     */
    public function getAllDoctorsWithSlots(string $date): array
    {
        try {
            return User::query()
                ->where('is_doctor', true)
                ->orderBy('name')
                ->get()
                ->map(fn (User $doctor): array => [
                    'doctor' => $doctor,
                    'slots' => $this->getAvailableSlots($doctor->id, $date),
                ])
                ->all();
        } catch (Throwable $exception) {
            Log::error('Unable to get all doctors with availability slots.', [
                'date' => $date,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function isSlotAvailable(int $doctorId, string $date, string $startTime): bool
    {
        try {
            return ($this->getAvailableSlots($doctorId, $date)[CarbonImmutable::parse($startTime)->format('H:i')] ?? false) === true;
        } catch (Throwable $exception) {
            Log::error('Unable to check whether a doctor slot is available.', [
                'doctor_id' => $doctorId,
                'date' => $date,
                'start_time' => $startTime,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @param  array{name: ?string, phone: ?string, email?: ?string, reason: string, raw_message: string, session_id: string}  $patient
     */
    public function bookAppointment(
        string $date,
        string $startTime,
        array $patient,
        ?int $preferredDoctorId = null,
        ?Service $service = null,
    ): ?AppointmentRequest {
        $currentHospital = hospital();
        if ($service !== null
            && ($service->is_active !== true
                || $currentHospital === null
                || (int) $service->hospital_id !== (int) $currentHospital->id)
        ) {
            throw new InvalidArgumentException('The selected service is not available to this hospital.');
        }

        try {
            return DB::transaction(function () use (
                $date,
                $startTime,
                $patient,
                $preferredDoctorId,
                $service,
                $currentHospital,
            ): ?AppointmentRequest {
                $requestedDate = CarbonImmutable::parse($date);
                $normalizedStart = CarbonImmutable::parse($startTime)->format('H:i');

                if ($requestedDate->lessThan(CarbonImmutable::today())) {
                    return null;
                }

                $doctors = User::query()
                    ->where('is_doctor', true)
                    ->where('is_active', true)
                    ->when(
                        filled($service?->requires_specialty),
                        fn ($query) => $query->where('specialization', $service->requires_specialty),
                    )
                    ->when(
                        $preferredDoctorId !== null,
                        fn ($query) => $query->whereKey($preferredDoctorId),
                    )
                    ->orderBy('id')
                    ->get();

                if ($doctors->isEmpty() && filled($service?->requires_specialty)) {
                    $doctors = User::query()
                        ->where('is_doctor', true)
                        ->where('is_active', true)
                        ->when(
                            $preferredDoctorId !== null,
                            fn ($query) => $query->whereKey($preferredDoctorId),
                        )
                        ->orderBy('id')
                        ->get();
                }

                foreach ($doctors as $doctor) {
                    if ($this->dateIsUnavailable($doctor->id, $requestedDate)) {
                        continue;
                    }

                    $availabilities = DoctorAvailability::query()
                        ->where('doctor_id', $doctor->id)
                        ->where('day_of_week', $requestedDate->dayOfWeek)
                        ->where('is_active', true)
                        ->orderBy('start_time')
                        ->lockForUpdate()
                        ->get();

                    foreach ($availabilities as $availability) {
                        foreach ($availability->generateSlots() as $slot) {
                            if ($slot['start'] !== $normalizedStart) {
                                continue;
                            }

                            $slotDateTime = CarbonImmutable::parse($requestedDate->toDateString().' '.$slot['start']);
                            if ($slotDateTime->lessThanOrEqualTo(CarbonImmutable::now())) {
                                continue;
                            }

                            $bookedCount = AppointmentRequest::query()
                                ->where('doctor_id', $doctor->id)
                                ->whereDate('preferred_date', $requestedDate->toDateString())
                                ->whereTime(
                                    'slot_start_time',
                                    CarbonImmutable::parse($slot['start'])->format('H:i:s'),
                                )
                                ->whereIn('status', [
                                    AppointmentRequest::STATUS_PENDING,
                                    AppointmentRequest::STATUS_CONFIRMED,
                                ])
                                ->count();

                            $activeHoldCount = SlotHold::query()
                                ->active()
                                ->where('doctor_id', $doctor->id)
                                ->whereNull('appointment_request_id')
                                ->whereBetween('slot_start_at', [
                                    $requestedDate->toDateString().' 00:00:00',
                                    $requestedDate->toDateString().' 23:59:59',
                                ])
                                ->whereTime(
                                    'slot_start_at',
                                    CarbonImmutable::parse($slot['start'])->format('H:i:s'),
                                )
                                ->count();

                            if ($bookedCount + $activeHoldCount >= $availability->max_patients_per_slot) {
                                continue;
                            }

                            $effectiveAmount = (float) (
                                $service?->price
                                ?? $currentHospital?->deposit_amount
                                ?? pearlie_config('appointment.deposit_amount')
                            );
                            $isFreeService = $service !== null && $effectiveAmount <= 0;
                            $appointment = AppointmentRequest::query()->create([
                                'session_id' => $patient['session_id'],
                                'patient_id' => auth()->user()?->role === 'patient' ? auth()->id() : null,
                                'name' => $patient['name'],
                                'phone' => $patient['phone'],
                                'email' => $patient['email'] ?? null,
                                'preferred_date' => $requestedDate->toDateString(),
                                'reason' => $patient['reason'],
                                'raw_message' => $patient['raw_message'],
                                'status' => $isFreeService
                                    ? AppointmentRequest::STATUS_CONFIRMED
                                    : AppointmentRequest::STATUS_PENDING,
                                'status_updated_at' => $isFreeService ? now() : null,
                                'doctor_id' => $doctor->id,
                                'slot_start_time' => $slot['start'],
                                'slot_end_time' => $slot['end'],
                                'service_id' => $service?->id,
                                'booking_fee' => (int) round($effectiveAmount),
                                'payment_amount' => $service !== null ? $effectiveAmount : null,
                            ]);

                            if ($currentHospital?->hasFeature('mpesa') && $effectiveAmount > 0) {
                                SlotHold::query()->create([
                                    'hospital_id' => $appointment->hospital_id,
                                    'doctor_id' => $doctor->id,
                                    'appointment_request_id' => $appointment->id,
                                    'slot_start_at' => $slotDateTime,
                                    'slot_end_at' => CarbonImmutable::parse(
                                        $requestedDate->toDateString().' '.$slot['end'],
                                    ),
                                    'expires_at' => now()->addMinutes(5),
                                ]);
                            }

                            return $appointment;
                        }
                    }
                }

                return null;
            });
        } catch (Throwable $exception) {
            Log::error('Unable to book an appointment in a doctor slot.', [
                'date' => $date,
                'start_time' => $startTime,
                'preferred_doctor_id' => $preferredDoctorId,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<int, array{date: string, start: string, end: string}>
     */
    public function getNextAvailableSlots(int $doctorId, int $count = 5): array
    {
        try {
            if ($count < 1) {
                return [];
            }

            $nextSlots = [];
            $today = CarbonImmutable::today();

            for ($dayOffset = 0; $dayOffset < 90 && count($nextSlots) < $count; $dayOffset++) {
                $date = $today->addDays($dayOffset);
                $availabilities = DoctorAvailability::query()
                    ->where('doctor_id', $doctorId)
                    ->where('day_of_week', $date->dayOfWeek)
                    ->where('is_active', true)
                    ->orderBy('start_time')
                    ->get();

                $availableStarts = $this->getAvailableSlots($doctorId, $date->toDateString());

                foreach ($availabilities as $availability) {
                    foreach ($availability->generateSlots() as $slot) {
                        if (($availableStarts[$slot['start']] ?? false) !== true) {
                            continue;
                        }

                        $nextSlots[] = [
                            'date' => $date->toDateString(),
                            'start' => $slot['start'],
                            'end' => $slot['end'],
                        ];

                        if (count($nextSlots) >= $count) {
                            break 2;
                        }
                    }
                }
            }

            return $nextSlots;
        } catch (Throwable $exception) {
            Log::error('Unable to find the next available doctor slots.', [
                'doctor_id' => $doctorId,
                'count' => $count,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    public function isDoctorAvailable(int $doctorId, string $date): bool
    {
        try {
            $requestedDate = CarbonImmutable::parse($date);

            return User::query()->whereKey($doctorId)->where('is_doctor', true)->exists()
                && ! $this->dateIsUnavailable($doctorId, $requestedDate)
                && DoctorAvailability::query()
                    ->where('doctor_id', $doctorId)
                    ->where('day_of_week', $requestedDate->dayOfWeek)
                    ->where('is_active', true)
                    ->exists();
        } catch (Throwable $exception) {
            Log::error('Unable to check doctor availability for a date.', [
                'doctor_id' => $doctorId,
                'date' => $date,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    private function dateIsUnavailable(int $doctorId, CarbonImmutable $date): bool
    {
        return DoctorUnavailableDate::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('date', $date->toDateString())
            ->exists();
    }
}
