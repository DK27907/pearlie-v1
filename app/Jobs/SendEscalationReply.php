<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\EscalationReply;
use App\Models\Hospital;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use App\Support\CacheLockRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendEscalationReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $replyId, public readonly int $hospitalId) {}

    public function handle(NotificationService $notifications, WhatsAppService $whatsApp): void
    {
        $hospital = Hospital::withoutGlobalScopes()->find($this->hospitalId);
        if (! $hospital) {
            throw new RuntimeException('The escalation reply hospital no longer exists.');
        }

        $hadCurrentHospital = app()->bound('currentHospital');
        $previousHospital = $hadCurrentHospital ? app('currentHospital') : null;
        app()->instance('currentHospital', $hospital);

        try {
            CacheLockRunner::run(Cache::lock('escalation-reply:'.$this->replyId, 180), 5, function () use ($notifications, $whatsApp): void {
                $reply = EscalationReply::withoutGlobalScopes()->with('escalation')->findOrFail($this->replyId);
                if ($reply->status === EscalationReply::STATUS_SENT) {
                    return;
                }

                $reply->forceFill([
                    'status' => EscalationReply::STATUS_SENDING,
                    'attempts' => $reply->attempts + 1,
                    'last_error' => null,
                ])->save();

                try {
                    $phone = $reply->escalation->patient_phone;
                    if (! $phone) {
                        throw new RuntimeException('No patient phone number is available.');
                    }

                    $body = sprintf(
                        '%s escalation #%d — %s',
                        $reply->hospital->chatbotName(),
                        $reply->escalation_id,
                        $reply->message,
                    );
                    $delivered = $reply->channel === 'whatsapp'
                        ? $whatsApp->sendMessage($phone, $body)['success']
                        : $notifications->sendSms($phone, $body);

                    if (! $delivered) {
                        throw new RuntimeException('The patient messaging provider did not accept the reply.');
                    }

                    DB::transaction(function () use ($reply, $body): void {
                        Conversation::query()->create([
                            'session_id' => $reply->escalation->session_id,
                            'user_message' => '',
                            'ai_response' => $body,
                            'confidence_score' => null,
                            'channel' => 'human',
                            'escalated' => true,
                        ]);
                        $reply->forceFill([
                            'status' => EscalationReply::STATUS_SENT,
                            'sent_at' => now(),
                            'last_error' => null,
                        ])->save();
                    });
                } catch (Throwable $exception) {
                    $reply->forceFill([
                        'status' => EscalationReply::STATUS_PENDING,
                        'last_error' => 'Delivery attempt failed.',
                    ])->save();

                    Log::warning('Escalation reply delivery attempt failed.', [
                        'escalation_reply_id' => $reply->id,
                        'hospital_id' => $this->hospitalId,
                        'channel' => $reply->channel,
                        'exception_class' => $exception::class,
                    ]);

                    throw $exception;
                }
            });
        } finally {
            if ($hadCurrentHospital) {
                app()->instance('currentHospital', $previousHospital);
            } else {
                app()->forgetInstance('currentHospital');
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        EscalationReply::withoutGlobalScopes()
            ->whereKey($this->replyId)
            ->where('hospital_id', $this->hospitalId)
            ->where('status', '!=', EscalationReply::STATUS_SENT)
            ->update([
                'status' => EscalationReply::STATUS_FAILED,
                'last_error' => 'Delivery failed after automatic retries.',
                'updated_at' => now(),
            ]);

        Log::error('Escalation reply exhausted its automatic delivery retries.', [
            'escalation_reply_id' => $this->replyId,
            'hospital_id' => $this->hospitalId,
            'exception_class' => $exception::class,
        ]);
    }
}
