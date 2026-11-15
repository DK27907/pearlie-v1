<?php

namespace App\Services;

use App\Exceptions\MissingProviderCredentialsException;
use App\Models\AppointmentRequest;
use App\Models\Hospital;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AppointmentFollowUpService
{
    public function __construct(
        private readonly WhatsAppService $whatsApp,
        private readonly NotificationService $notifications,
    ) {}

    public function sendIfDue(int $appointmentId, int $hospitalId): ?bool
    {
        return Cache::lock("appointment-follow-up:{$hospitalId}:{$appointmentId}", 120)
            ->block(10, function () use ($appointmentId, $hospitalId): ?bool {
                $appointment = DB::transaction(function () use ($appointmentId, $hospitalId): ?AppointmentRequest {
                    return AppointmentRequest::withoutGlobalScopes()
                        ->where('hospital_id', $hospitalId)
                        ->whereKey($appointmentId)
                        ->where('status', AppointmentRequest::STATUS_COMPLETED)
                        ->where('updated_at', '<=', now()->subHours(24))
                        ->whereNull('feedback_requested_at')
                        ->lockForUpdate()
                        ->first();
                });

                if ($appointment === null) {
                    return null;
                }

                $hospital = Hospital::withoutGlobalScopes()
                    ->whereKey($hospitalId)
                    ->firstOrFail();
                $hadCurrentHospital = app()->bound('currentHospital');
                $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
                app()->instance('currentHospital', $hospital);

                try {
                    $doctorName = User::withoutGlobalScopes()
                        ->where('hospital_id', $hospitalId)
                        ->whereKey($appointment->doctor_id)
                        ->value('name');
                    $doctorLabel = filled($doctorName)
                        ? (preg_match('/^dr\.?\s/i', $doctorName) === 1 ? $doctorName : 'Dr. '.$doctorName)
                        : 'your doctor';
                    $message = sprintf(
                        'Hi %s, thank you for visiting %s. We\'d love to hear about your experience with %s. Please reply with a number from 1 (Poor) to 5 (Excellent).',
                        $appointment->name ?: 'there',
                        $hospital->name,
                        $doctorLabel,
                    );

                    $accepted = $this->whatsApp->isConfigured()
                        && $this->whatsApp->sendMessage(
                            (string) $appointment->phone,
                            $message,
                            $appointment->id,
                        )['success'];

                    if (! $accepted) {
                        try {
                            $accepted = $this->notifications->sendSms(
                                (string) $appointment->phone,
                                $message,
                                $appointment->id,
                            );
                        } catch (MissingProviderCredentialsException $exception) {
                            $this->notifications->recordUnavailableDelivery(
                                'sms',
                                mb_strtolower($exception->provider),
                                $appointment->id,
                            );
                            $accepted = false;
                        }
                    }

                    if (! $accepted) {
                        return false;
                    }

                    return DB::transaction(function () use ($appointmentId, $hospitalId): bool {
                        $lockedAppointment = AppointmentRequest::withoutGlobalScopes()
                            ->where('hospital_id', $hospitalId)
                            ->whereKey($appointmentId)
                            ->whereNull('feedback_requested_at')
                            ->lockForUpdate()
                            ->first();

                        if ($lockedAppointment === null) {
                            return false;
                        }

                        $lockedAppointment->forceFill([
                            'feedback_requested_at' => now(),
                        ])->save();

                        return true;
                    });
                } finally {
                    if ($hadCurrentHospital) {
                        app()->instance('currentHospital', $previousHospital);
                    } else {
                        app()->forgetInstance('currentHospital');
                    }
                }
            });
    }

    public function captureReply(
        Hospital $hospital,
        string $channel,
        string $profileIdentity,
        string $message,
    ): ?string {
        $identityHash = hash('sha256', implode(':', [
            $hospital->id,
            $channel,
            $profileIdentity,
        ]));
        $reply = trim($message);

        return DB::transaction(function () use ($hospital, $identityHash, $reply): ?string {
            $appointment = AppointmentRequest::withoutGlobalScopes()
                ->where('hospital_id', $hospital->id)
                ->where('feedback_identity_hash', $identityHash)
                ->where('status', AppointmentRequest::STATUS_COMPLETED)
                ->orderByDesc('preferred_date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($appointment === null || $appointment->feedback_requested_at === null) {
                return null;
            }

            if ($appointment->feedback_score === null) {
                if (preg_match('/^[1-5]$/', $reply) !== 1) {
                    return null;
                }

                $score = (int) $reply;
                $appointment->forceFill(['feedback_score' => $score])->save();
                $response = 'Thank you for your feedback! If you have any comments, just type them here.';
                $reviewUrl = data_get($hospital->settings, 'google_review_url');

                if (
                    $score >= 4
                    && is_string($reviewUrl)
                    && filter_var($reviewUrl, FILTER_VALIDATE_URL) !== false
                    && in_array(strtolower((string) parse_url($reviewUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
                ) {
                    $response .= ' If you had a great experience, please leave us a review on Google: '.$reviewUrl;
                }

                return $response;
            }

            if ($appointment->feedback_comment !== null || $reply === '' || preg_match('/^[1-5]$/', $reply) === 1) {
                return null;
            }

            $appointment->forceFill(['feedback_comment' => $reply])->save();

            return 'We appreciate your feedback. Have a great day!';
        });
    }
}
