<?php

namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class HospitalOnboardingController extends Controller
{
    public function show(): View
    {
        return $this->settingsPage(false);
    }

    public function adminSettings(): View
    {
        return $this->settingsPage(true);
    }

    private function settingsPage(bool $adminSettings): View
    {
        $hospital = hospital();

        return view('hospital.onboarding', [
            'hospital' => $hospital,
            'adminSettings' => $adminSettings,
            'formAction' => $adminSettings ? route('admin.settings.update') : route('hospital.onboarding.update'),
            'checklist' => [
                'profile' => filled($hospital->phone) && filled($hospital->address),
                'branding' => filled($hospital->primary_color),
                'doctors' => $hospital->doctors()->exists(),
                'knowledge' => $hospital->knowledgeBases()->exists(),
                'payments' => ! $hospital->hasFeature('mpesa') || $this->hasValidMpesaCredentials($hospital),
            ],
        ]);
    }

    private function hasValidMpesaCredentials(Hospital $hospital): bool
    {
        $credential = $hospital->integrationCredentials()
            ->where('provider', HospitalIntegrationCredential::PROVIDER_MPESA)
            ->where('is_active', true)
            ->first();
        $credentials = $credential?->credentials;

        if (! is_array($credentials)) {
            return false;
        }

        foreach (['consumer_key', 'consumer_secret', 'passkey', 'shortcode', 'callback_url'] as $key) {
            if (blank($credentials[$key] ?? null)) {
                return false;
            }
        }

        return in_array($credentials['environment'] ?? null, ['sandbox', 'production'], true);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:100'],
            'county' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:40'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'whatsapp_number' => ['nullable', 'string', 'max:40'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:255'],
            'whatsapp_access_token' => ['nullable', 'string', 'max:1000'],
            'whatsapp_app_secret' => ['nullable', 'string', 'max:500'],
            'whatsapp_verify_token' => ['nullable', 'string', 'max:500'],
            'whatsapp_api_version' => ['required', 'regex:/^v[0-9]+\\.[0-9]+$/'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'default_language' => ['required', 'in:en,sw'],
            'supported_languages' => ['required', 'array', 'min:1'],
            'supported_languages.*' => ['required', 'in:en,sw'],
            'settings' => ['nullable', 'array'],
            'settings.about' => ['nullable', 'string', 'max:3000'],
            'settings.ai_instructions' => ['nullable', 'string', 'max:4000'],
            'hours_emergency' => ['required', 'string', 'max:100'],
            'hours_outpatient' => ['required', 'string', 'max:100'],
            'mpesa_shortcode' => ['nullable', 'string', 'max:20'],
            'mpesa_consumer_key' => ['nullable', 'string', 'max:255'],
            'mpesa_consumer_secret' => ['nullable', 'string', 'max:255'],
            'mpesa_passkey' => ['nullable', 'string', 'max:500'],
            'slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'no_show_grace_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'deposit_amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $tenant = hospital();
        unset($data['logo']);
        $settings = $data['settings'] ?? [];
        unset($data['settings']);
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
        $tenant->update([
            ...$data,
            'settings' => array_replace((array) $tenant->settings, $settings),
        ]);

        if ($request->hasFile('logo')) {
            $oldLogoPath = $tenant->logo_url;
            $path = $request->file('logo')->store('hospital-logos', 'public');
            $tenant->update(['logo_url' => $path]);

            if ($oldLogoPath && str_starts_with($oldLogoPath, 'hospital-logos/')) {
                Storage::disk('public')->delete($oldLogoPath);
            }
        }

        return back()->with('status', 'Hospital profile and assistant settings saved.');
    }
}
