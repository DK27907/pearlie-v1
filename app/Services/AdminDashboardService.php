<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Conversation;
use App\Models\Escalation;
use App\Models\Hospital;
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

    /**
     * @return array{english: int, swahili: int, swahili_percent: int}
     */
    public function getLanguageBreakdown(Hospital $hospital): array
    {
        $counts = Conversation::withoutGlobalScope('hospital')
            ->where('hospital_id', $hospital->id)
            ->whereBetween('created_at', [now()->startOfMonth(), now()])
            ->whereIn('language', ['en', 'english', 'sw', 'swahili'])
            ->select('language')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('language')
            ->pluck('aggregate', 'language');
        $english = (int) ($counts['en'] ?? 0) + (int) ($counts['english'] ?? 0);
        $swahili = (int) ($counts['sw'] ?? 0) + (int) ($counts['swahili'] ?? 0);
        $total = $english + $swahili;

        return [
            'english' => $english,
            'swahili' => $swahili,
            'swahili_percent' => $total > 0 ? (int) round($swahili / $total * 100) : 0,
        ];
    }
}
