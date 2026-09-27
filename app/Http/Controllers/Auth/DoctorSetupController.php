<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreDoctorPasswordRequest;
use App\Services\DoctorManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class DoctorSetupController extends Controller
{
    public function __construct(
        private readonly DoctorManagementService $doctorManagement,
    ) {}

    public function show(string $token): View
    {
        return view('auth.doctor-setup', [
            'invite' => $this->doctorManagement->findValidInvite($token),
            'token' => $token,
        ]);
    }

    public function store(StoreDoctorPasswordRequest $request, string $token): RedirectResponse
    {
        try {
            $this->doctorManagement->completeSetup($token, $request->validated('password'));
            $request->session()->regenerate();

            return redirect()->route('doctor.dashboard')->with('status', 'Your password is set and your doctor account is ready.');
        } catch (NotFoundHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Unable to complete doctor account setup.', [
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }
}
