<?php

namespace App\Http\Controllers;

use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\Service;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class BookingLookupController extends Controller
{
    public function show(string $bookingReference): JsonResponse
    {
        $appointment = AppointmentRequest::withoutGlobalScopes()
            ->where('booking_reference', $bookingReference)
            ->first();

        if ($appointment === null) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        $hospital = Hospital::query()->findOrFail($appointment->hospital_id);
        $serviceName = Service::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->whereKey($appointment->service_id)
            ->value('name');
        $doctorName = User::withoutGlobalScopes()
            ->where('hospital_id', $hospital->id)
            ->whereKey($appointment->doctor_id)
            ->value('name');

        return response()->json([
            'booking_reference' => $appointment->booking_reference,
            'status' => $appointment->status,
            'service' => $serviceName,
            'doctor' => $doctorName,
            'date' => $appointment->preferred_date?->toDateString(),
            'time' => $appointment->slot_start_time ? substr((string) $appointment->slot_start_time, 0, 5) : null,
            'hospital' => [
                'name' => $hospital->name,
                'address' => $hospital->address,
            ],
        ]);
    }
}
