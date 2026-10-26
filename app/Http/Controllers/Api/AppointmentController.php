<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(AppointmentRequest::statuses())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $appointments = $hospital->appointments()
            ->where('hospital_id', $hospital->id)
            ->when(
                isset($validated['status']),
                fn ($query) => $query->where('status', $validated['status']),
            )
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->through(fn (AppointmentRequest $appointment): array => $this->publicFields($appointment));

        return response()->json($appointments);
    }

    public function show(int $appointmentId): JsonResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        $appointment = $hospital->appointments()
            ->where('hospital_id', $hospital->id)
            ->whereKey($appointmentId)
            ->firstOrFail();

        return response()->json(['data' => $this->publicFields($appointment)]);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function publicFields(AppointmentRequest $appointment): array
    {
        return [
            'id' => (int) $appointment->id,
            'booking_reference' => $appointment->booking_reference,
            'service_id' => $appointment->service_id,
            'doctor_id' => $appointment->doctor_id,
            'preferred_date' => $appointment->preferred_date?->toDateString(),
            'slot_start_time' => $appointment->slot_start_time,
            'slot_end_time' => $appointment->slot_end_time,
            'status' => $appointment->status,
            'payment_status' => $appointment->payment_status,
            'created_at' => $appointment->created_at?->toIso8601String(),
            'updated_at' => $appointment->updated_at?->toIso8601String(),
        ];
    }
}
