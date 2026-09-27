<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class AdminDashboardService
{
    /**
     * @return array{
     *     total_conversations: int,
     *     pending_escalations: int,
     *     pending_appointments: int,
     *     paid_appointments_today: int,
     *     no_shows_this_week: int,
     *     total_doctors: int,
     *     recent_escalations: Collection,
     *     recent_appointments: Collection,
     *     pending_no_shows: Collection
     * }
     */
    public function dashboardData(NoShowService $noShowService): array
    {
        $weekStart = now()->startOfWeek();

        return [
            'total_conversations' => Conversation::query()->count(),
            'pending_escalations' => Escalation::query()
                ->where('status', Escalation::STATUS_PENDING)
                ->count(),
            'pending_appointments' => AppointmentRequest::query()
                ->where('status', AppointmentRequest::STATUS_PENDING)
                ->count(),
            'paid_appointments_today' => AppointmentRequest::query()
                ->where('payment_status', 'paid')
                ->whereDate('paid_at', today())
                ->count(),
            'no_shows_this_week' => AppointmentRequest::query()
                ->noShow()
                ->where('marked_no_show_at', '>=', $weekStart)
                ->count(),
            'total_doctors' => User::query()
                ->where('is_doctor', true)
                ->count(),
            'recent_escalations' => Escalation::query()
                ->latest('created_at')
                ->limit(5)
                ->get(),
            'recent_appointments' => AppointmentRequest::query()
                ->latest('created_at')
                ->limit(5)
                ->get(),
            'pending_no_shows' => $noShowService->pendingNoShowAppointments()
                ->orderBy('slot_end_time')
                ->limit(5)
                ->get(),
        ];
    }
}
