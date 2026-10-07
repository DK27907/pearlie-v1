<?php

namespace App\Http\Controllers\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PatientController extends Controller
{
    public function index(Request $request): View
    {
        $today = today()->toDateString();
        $patients = $request->user()
            ->doctorAppointments()
            ->leftJoin('users as patient_users', function (JoinClause $join): void {
                $join->on('patient_users.id', '=', 'appointment_requests.patient_id')
                    ->on('patient_users.hospital_id', '=', 'appointment_requests.hospital_id');
            })
            ->select('appointment_requests.patient_id')
            ->selectRaw('COALESCE(MAX(patient_users.name), MAX(appointment_requests.name)) as name')
            ->selectRaw('COALESCE(MAX(patient_users.phone), MAX(appointment_requests.phone)) as phone')
            ->selectRaw('MIN(appointment_requests.id) as representative_appointment_id')
            ->selectRaw('MAX(CASE WHEN appointment_requests.preferred_date < ? THEN appointment_requests.preferred_date END) as last_appointment_date', [$today])
            ->selectRaw('MIN(CASE WHEN appointment_requests.preferred_date >= ? THEN appointment_requests.preferred_date END) as next_appointment_date', [$today])
            ->selectRaw('COUNT(appointment_requests.id) as total_appointments')
            ->selectRaw(
                'SUM(CASE WHEN appointment_requests.payment_status = ? THEN COALESCE(appointment_requests.payment_amount, appointment_requests.booking_fee, 0) ELSE 0 END) as total_paid',
                ['paid'],
            )
            ->with('patient:id,name,phone')
            ->groupBy('appointment_requests.patient_id')
            ->groupByRaw('CASE WHEN appointment_requests.patient_id IS NULL THEN appointment_requests.name END')
            ->groupByRaw('CASE WHEN appointment_requests.patient_id IS NULL THEN appointment_requests.phone END')
            ->orderBy('name')
            ->paginate(15);

        return view('doctor.patients.index', [
            'patients' => $patients,
        ]);
    }
}
