<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use App\Services\BookingChatService;
use App\Services\DoctorAvailabilityService;
use App\Services\PaymentLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WalkInBookingController extends Controller
{
    public function create(): View
    {
        $hospital = $this->currentHospital();

        return view('admin.walk-in.create', [
            'hospital' => $hospital,
            'services' => $hospital->services()->active()->orderBy('name')->get(),
            'defaultPaymentMode' => $hospital->bookingPaymentMode(),
            'today' => today()->toDateString(),
        ]);
    }

    public function doctors(Request $request, DoctorAvailabilityService $availability): JsonResponse
    {
        $hospital = $this->currentHospital();
        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')
                    ->where('hospital_id', $hospital->id)
                    ->where('is_active', true),
            ],
        ]);

        $doctors = $availability->getInHospitalDoctorsForService($hospital, (int) $validated['service_id']);

        return response()->json($doctors->map(fn (User $doctor): array => [
            'id' => $doctor->id,
            'name' => $doctor->name,
        ])->values());
    }

    public function slots(Request $request, DoctorAvailabilityService $availability): JsonResponse
    {
        $hospital = $this->currentHospital();
        $validated = $request->validate([
            'doctor_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);
        $doctorId = (int) $validated['doctor_id'];
        $doctorExists = User::withoutGlobalScope('hospital')
            ->where('hospital_id', $hospital->id)
            ->whereKey($doctorId)
            ->where('is_doctor', true)
            ->where('is_active', true)
            ->whereHas('availabilities', fn ($query) => $query->where('hospital_id', $hospital->id))
            ->exists();

        abort_unless($doctorExists, 404);

        $slots = $availability->getAvailableSlots(
            $doctorId,
            CarbonImmutable::createFromFormat('Y-m-d', $validated['date'])->toDateString(),
        );

        return response()->json(collect($slots)
            ->filter(fn (bool $isAvailable): bool => $isAvailable)
            ->map(fn (bool $isAvailable, string $time): array => [
                'time' => $time,
                'label' => CarbonImmutable::createFromFormat('H:i', $time)->format('g:i A'),
            ])
            ->values());
    }

    public function store(
        Request $request,
        BookingChatService $bookingChat,
        DoctorAvailabilityService $availability,
    ): RedirectResponse {
        $hospital = $this->currentHospital();
        $validated = $request->validate([
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')
                    ->where('hospital_id', $hospital->id)
                    ->where('is_active', true),
            ],
            'doctor_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'patient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:40'],
            'payment_override' => ['nullable', Rule::in(Hospital::BOOKING_PAYMENT_MODES)],
        ]);
        $eligibleDoctor = $availability
            ->getInHospitalDoctorsForService($hospital, (int) $validated['service_id'])
            ->firstWhere('id', (int) $validated['doctor_id']);

        if (! $eligibleDoctor) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Select an in-hospital doctor who offers this service.',
            ]);
        }

        $appointment = $bookingChat->createBookingFromData($hospital, $validated);

        return redirect()->route('admin.walk-in.confirmation', $appointment);
    }

    public function confirmation(AppointmentRequest $appointment): View
    {
        $this->authorize('view', $appointment);
        $appointment->loadMissing(['doctor', 'service', 'receipt']);

        return view('admin.walk-in.confirmation', compact('appointment'));
    }

    public function markPaidOnArrival(
        Request $request,
        AppointmentRequest $appointment,
        PaymentLifecycleService $payments,
    ): RedirectResponse {
        abort_unless(
            $appointment->payment_method === Hospital::BOOKING_PAYMENT_MODE_CASH
                && $appointment->payment_status === 'pending',
            422,
            'Only pending cash walk-in payments can be marked paid on arrival.',
        );

        $cashier = $request->user();
        abort_unless($cashier instanceof User, 403);
        $amount = (float) ($appointment->payment_amount ?? $appointment->booking_fee);
        $receipt = $payments->markPaidAtHospital($appointment, $amount, 'cash', $cashier);

        return redirect()->to($receipt->signedPrintUrl());
    }

    private function currentHospital(): Hospital
    {
        $hospital = hospital();
        abort_unless($hospital instanceof Hospital, 404);

        return $hospital;
    }
}
