<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $hospitals = Hospital::query()
            ->withCount('doctors')
            ->withCount([
                'appointments as appointments_this_month_count' => fn ($query) => $query
                    ->whereBetween('preferred_date', [$startOfMonth->toDateString(), $endOfMonth->toDateString()]),
            ])
            ->withSum([
                'appointments as revenue_this_month' => fn ($query) => $query
                    ->where('payment_status', 'paid')
                    ->whereBetween('paid_at', [$startOfMonth, $endOfMonth]),
            ], 'payment_amount')
            ->orderBy('name')
            ->get();

        return view('superadmin.dashboard', [
            'hospitals' => $hospitals,
            'totalHospitals' => $hospitals->count(),
            'activeHospitals' => $hospitals->where('is_active', true)->count(),
            'trialHospitals' => $hospitals->where('subscription_status', 'trial')->count(),
            'monthlyRevenue' => (float) $hospitals->sum('revenue_this_month'),
        ]);
    }
}
