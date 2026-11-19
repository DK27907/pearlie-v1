<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Hospital;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        // Bypass only the request scope; each relationship retains its hospital_id constraint.
        $hospitals = Hospital::query()
            ->withCount([
                'doctors' => fn ($query) => $query->withoutGlobalScope('hospital'),
            ])
            ->withCount([
                'appointments as appointments_this_month_count' => fn ($query) => $query
                    ->withoutGlobalScope('hospital')
                    ->whereBetween('preferred_date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()]),
            ])
            ->withSum([
                'appointments as revenue_this_month' => fn ($query) => $query
                    ->withoutGlobalScope('hospital')
                    ->where('payment_status', 'paid')
                    ->whereBetween('paid_at', [$startOfMonth, $endOfMonth]),
            ], 'payment_amount')
            ->orderBy('name')
            ->get();

        $languageRows = Conversation::withoutGlobalScope('hospital')
            ->whereBetween('created_at', [$startOfMonth, Carbon::now()])
            ->whereIn('language', ['en', 'english', 'sw', 'swahili'])
            ->select('hospital_id', 'language')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('hospital_id', 'language')
            ->get();
        $languageCountsByHospital = [];
        foreach ($languageRows as $row) {
            $key = in_array($row->language, ['sw', 'swahili'], true) ? 'swahili' : 'english';
            $languageCountsByHospital[$row->hospital_id][$key] =
                ($languageCountsByHospital[$row->hospital_id][$key] ?? 0) + (int) $row->aggregate;
        }
        $hospitalLanguageBreakdown = $hospitals->map(fn (Hospital $hospital): array => [
            'name' => $hospital->name,
            'english' => (int) ($languageCountsByHospital[$hospital->id]['english'] ?? 0),
            'swahili' => (int) ($languageCountsByHospital[$hospital->id]['swahili'] ?? 0),
        ])->all();
        $englishConversations = array_sum(array_column($hospitalLanguageBreakdown, 'english'));
        $swahiliConversations = array_sum(array_column($hospitalLanguageBreakdown, 'swahili'));
        $conversationTotal = $englishConversations + $swahiliConversations;

        return view('superadmin.dashboard', [
            'hospitals' => $hospitals,
            'totalHospitals' => $hospitals->count(),
            'activeHospitals' => $hospitals->where('is_active', true)->count(),
            'trialHospitals' => $hospitals->where('subscription_status', 'trial')->count(),
            'monthlyRevenue' => (float) $hospitals->sum('revenue_this_month'),
            'languageBreakdown' => [
                'english' => $englishConversations,
                'swahili' => $swahiliConversations,
                'swahili_percent' => $conversationTotal > 0
                    ? (int) round($swahiliConversations / $conversationTotal * 100)
                    : 0,
            ],
            'hospitalLanguageBreakdown' => $hospitalLanguageBreakdown,
        ]);
    }
}
