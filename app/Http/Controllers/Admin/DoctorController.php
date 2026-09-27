<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexDoctorsRequest;
use App\Http\Requests\Admin\StoreDoctorRequest;
use App\Http\Requests\Admin\UpdateDoctorRequest;
use App\Models\User;
use App\Services\DoctorManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DoctorController extends Controller
{
    public function __construct(
        private readonly DoctorManagementService $doctorManagement,
    ) {}

    public function index(IndexDoctorsRequest $request): View
    {
        return view('admin.doctors.index', [
            'doctors' => $this->doctorManagement->paginateDoctors($request->validated('search')),
            'search' => $request->validated('search') ?? '',
        ]);
    }

    public function create(): View
    {
        return view('admin.doctors.create');
    }

    public function store(StoreDoctorRequest $request): RedirectResponse
    {
        $result = $this->doctorManagement->createDoctor($request->validated());

        if (! $result['invite_sent']) {
            return redirect()
                ->route('admin.doctors.index')
                ->with('error', 'Doctor account was created, but the invitation email could not be sent. Use Resend invite after correcting mail configuration.');
        }

        return redirect()
            ->route('admin.doctors.index')
            ->with('status', 'Doctor account created and invitation email sent.');
    }

    public function edit(int $doctor): View
    {
        return view('admin.doctors.edit', [
            'doctor' => $this->doctorRecord($doctor),
        ]);
    }

    public function update(UpdateDoctorRequest $request, int $doctor): RedirectResponse
    {
        $record = $this->doctorRecord($doctor);
        $this->doctorManagement->updateDoctor($record, $request->validated());

        return redirect()->route('admin.doctors.index')->with('status', 'Doctor details updated.');
    }

    public function destroy(int $doctor): RedirectResponse
    {
        $this->doctorManagement->deactivateDoctor($this->doctorRecord($doctor));

        return redirect()->route('admin.doctors.index')->with('status', 'Doctor account deactivated.');
    }

    public function invite(int $doctor): RedirectResponse
    {
        $sent = $this->doctorManagement->resendInvite($this->doctorRecord($doctor));

        return redirect()
            ->route('admin.doctors.index')
            ->with(
                $sent ? 'status' : 'error',
                $sent ? 'Doctor invitation email sent.' : 'Invitation could not be sent. Check mail configuration and try again.',
            );
    }

    private function doctorRecord(int $id): User
    {
        return User::query()
            ->where(function ($query): void {
                $query->where('is_doctor', true)
                    ->orWhereHas('doctorInvites')
                    ->orWhereHas('doctorAppointments')
                    ->orWhereHas('availabilities');
            })
            ->findOrFail($id);
    }
}
