<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Services\HospitalManagementService;
use App\Services\HospitalInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HospitalController extends Controller
{
    public function index(Request $request): View
    {
        $hospitals = Hospital::query()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->trim()->toString();
                $query->where(fn ($query) => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'));
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('superadmin.hospitals.index', compact('hospitals'));
    }

    public function create(): View
    {
        return view('superadmin.hospitals.form', ['hospital' => new Hospital()]);
    }

    public function store(Request $request, HospitalManagementService $hospitals): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'alpha_dash', 'max:80', 'unique:hospitals,slug'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'county' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['nullable', 'string', 'max:1000'],
            'whatsapp_app_secret' => ['nullable', 'string', 'max:500'],
            'whatsapp_verify_token' => ['nullable', 'string', 'max:500'],
            'whatsapp_api_version' => ['nullable', 'regex:/^v[0-9]+\\.[0-9]+$/'],
            'mpesa_shortcode' => ['nullable', 'string', 'max:20'],
            'mpesa_consumer_key' => ['nullable', 'string', 'max:255'],
            'mpesa_consumer_secret' => ['nullable', 'string', 'max:255'],
            'mpesa_passkey' => ['nullable', 'string', 'max:500'],
            'deposit_amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'no_show_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'hours_emergency' => ['required', 'string', 'max:100'],
            'hours_outpatient' => ['required', 'string', 'max:100'],
            'default_language' => ['required', 'in:en,sw'],
            'supported_languages' => ['required', 'array', 'min:1'],
            'supported_languages.*' => ['required', 'in:en,sw'],
            'primary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'subscription_plan' => ['required', Rule::in(['starter', 'professional', 'enterprise'])],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $data['slug'] = Str::slug($data['slug']);
        $this->removeBlankSecrets($data);
        $hospital = $hospitals->create($data, (int) $request->user()->id);

        return redirect()->route('superadmin.hospitals.show', $hospital)
            ->with('status', 'Hospital created. Its administrator invitation has been sent.');
    }

    public function show(Hospital $hospital): View
    {
        $hospital->loadCount(['users', 'doctors', 'appointments', 'escalations']);

        return view('superadmin.hospitals.show', compact('hospital'));
    }

    public function edit(Hospital $hospital): View
    {
        return view('superadmin.hospitals.form', compact('hospital'));
    }

    public function update(Request $request, Hospital $hospital): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'alpha_dash', 'max:80', Rule::unique('hospitals', 'slug')->ignore($hospital->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'website' => ['nullable', 'url', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'county' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['nullable', 'string', 'max:1000'],
            'whatsapp_app_secret' => ['nullable', 'string', 'max:500'],
            'whatsapp_verify_token' => ['nullable', 'string', 'max:500'],
            'whatsapp_api_version' => ['required', 'regex:/^v[0-9]+\\.[0-9]+$/'],
            'mpesa_shortcode' => ['nullable', 'string', 'max:20'],
            'mpesa_consumer_key' => ['nullable', 'string', 'max:255'],
            'mpesa_consumer_secret' => ['nullable', 'string', 'max:255'],
            'mpesa_passkey' => ['nullable', 'string', 'max:500'],
            'deposit_amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'no_show_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'hours_emergency' => ['required', 'string', 'max:100'],
            'hours_outpatient' => ['required', 'string', 'max:100'],
            'default_language' => ['required', 'in:en,sw'],
            'supported_languages' => ['required', 'array', 'min:1'],
            'supported_languages.*' => ['required', 'in:en,sw'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'subscription_plan' => ['required', Rule::in(['starter', 'professional', 'enterprise'])],
            'subscription_status' => ['required', Rule::in(['active', 'trial', 'suspended'])],
            'trial_ends_at' => ['nullable', 'date'],
            'is_active' => ['required', 'boolean'],
        ]);

        $this->removeBlankSecrets($data);
        $hospital->update($data);

        return redirect()->route('superadmin.hospitals.show', $hospital)
            ->with('status', 'Hospital settings updated.');
    }

    public function destroy(Hospital $hospital): RedirectResponse
    {
        $this->suspend($hospital);

        return redirect()->route('superadmin.hospitals.index')
            ->with('status', 'Hospital access suspended. Data was retained.');
    }

    public function suspend(Hospital $hospital): RedirectResponse
    {
        $hospital->forceFill(['is_active' => false, 'subscription_status' => 'suspended'])->save();

        return back()->with('status', 'Hospital access suspended. Data was retained.');
    }

    public function activate(Hospital $hospital): RedirectResponse
    {
        $hospital->forceFill([
            'is_active' => true,
            'subscription_status' => $hospital->trial_ends_at?->isFuture() ? 'trial' : 'active',
        ])->save();

        return back()->with('status', 'Hospital access restored.');
    }

    public function inviteAdmin(Request $request, Hospital $hospital, HospitalInvitationService $invitations): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $invitations->send($hospital, $data['email'], (int) $request->user()->id);

        return back()->with('status', 'Administrator invitation sent.');
    }

    public function impersonate(Hospital $hospital, Request $request): RedirectResponse
    {
        $request->session()->put('impersonating_hospital_id', $hospital->id);
        Log::notice('Platform administrator started hospital impersonation.', [
            'user_id' => $request->user()->id,
            'hospital_id' => $hospital->id,
        ]);

        return redirect()->route('admin.dashboard')->with('status', 'You are viewing this hospital as a platform administrator.');
    }

    public function stopImpersonating(Request $request): RedirectResponse
    {
        Log::notice('Platform administrator ended hospital impersonation.', [
            'user_id' => $request->user()->id,
            'hospital_id' => $request->session()->get('impersonating_hospital_id'),
        ]);
        $request->session()->forget('impersonating_hospital_id');

        return redirect()->route('superadmin.hospitals.index')->with('status', 'Hospital view ended.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function removeBlankSecrets(array &$data): void
    {
        foreach ([
            'mpesa_consumer_key',
            'mpesa_consumer_secret',
            'mpesa_passkey',
            'whatsapp_access_token',
            'whatsapp_app_secret',
            'whatsapp_verify_token',
        ] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }
    }
}
