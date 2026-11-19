<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Services\AdminDashboardService;
use App\Services\NoShowService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly AdminDashboardService $dashboard,
        private readonly NoShowService $noShowService,
    ) {}

    public function index(): View
    {
        $data = $this->dashboard->dashboardData($this->noShowService);

        if (! array_key_exists('language_breakdown', $data)) {
            $hospital = hospital() ?? Hospital::query()->findOrFail(auth()->user()->hospital_id);
            $data['language_breakdown'] = $this->dashboard->getLanguageBreakdown($hospital);
        }

        return view('admin.dashboard', $data);
    }
}
