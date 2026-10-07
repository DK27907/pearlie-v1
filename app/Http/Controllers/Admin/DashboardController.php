<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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
        return view('admin.dashboard', $this->dashboard->dashboardData($this->noShowService));
    }
}
