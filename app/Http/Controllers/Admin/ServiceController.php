<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(): View
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        return view('admin.services.index', [
            'services' => $hospital->services()->orderBy('name')->paginate(25),
        ]);
    }

    public function create(): View
    {
        abort_unless(hospital(), 404);

        return view('admin.services.create', ['service' => new Service]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $hospital = hospital();
        abort_unless($hospital, 404);
        $hospital->services()->create($request->validated());

        return redirect()->route('admin.services.index')->with('status', 'Service created.');
    }

    public function edit(Service $service): View
    {
        $this->ensureCurrentHospitalOwns($service);

        return view('admin.services.edit', compact('service'));
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $this->ensureCurrentHospitalOwns($service);
        $service->update($request->validated());

        return redirect()->route('admin.services.index')->with('status', 'Service updated.');
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->ensureCurrentHospitalOwns($service);
        $service->update(['is_active' => false]);

        return redirect()->route('admin.services.index')->with('status', 'Service deactivated.');
    }

    public function toggleActive(Service $service): RedirectResponse
    {
        $this->ensureCurrentHospitalOwns($service);
        $service->update(['is_active' => ! $service->is_active]);

        return redirect()->route('admin.services.index')->with('status', 'Service status updated.');
    }

    private function ensureCurrentHospitalOwns(Service $service): void
    {
        abort_unless(
            hospital() && (int) $service->hospital_id === (int) hospital()->id,
            404,
        );
    }
}
