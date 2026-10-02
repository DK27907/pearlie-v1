<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateEmailSettingsRequest;
use App\Http\Requests\Admin\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\UpdateMpesaSettingsRequest;
use App\Http\Requests\Admin\UpdateSmsSettingsRequest;
use App\Http\Requests\Admin\UpdateWhatsAppSettingsRequest;
use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use App\Services\HospitalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const PROVIDERS = [
        HospitalIntegrationCredential::PROVIDER_MPESA,
        HospitalIntegrationCredential::PROVIDER_WHATSAPP,
        HospitalIntegrationCredential::PROVIDER_SMS,
        HospitalIntegrationCredential::PROVIDER_EMAIL,
    ];

    private const SECRET_FIELDS = [
        'mpesa' => ['consumer_key', 'consumer_secret', 'passkey'],
        'whatsapp' => ['access_token', 'verify_token', 'app_secret'],
        'sms' => ['api_key', 'account_sid', 'auth_token'],
        'email' => ['resend_api_key', 'smtp_password'],
    ];

    public function edit(Request $request, HospitalSettings $settings): View
    {
        $hospital = $this->currentHospital();
        $settings->for($hospital);
        $credentials = [];

        foreach (self::PROVIDERS as $provider) {
            $credentials[$provider] = $settings->credential($provider) ?? [];
            foreach (self::SECRET_FIELDS[$provider] as $field) {
                if (filled($credentials[$provider][$field] ?? null)) {
                    $credentials[$provider][$field] = '••••••••'.substr($credentials[$provider][$field], -4);
                }
            }
        }

        $activeTab = $request->query('tab', 'general');
        if (! in_array($activeTab, ['general', ...self::PROVIDERS], true)) {
            $activeTab = 'general';
        }

        return view('admin.settings.edit', [
            'activeTab' => $activeTab,
            'credentials' => $credentials,
            'settings' => $settings,
            'businessHours' => $settings->get('business_hours', []),
            'depositAmount' => $settings->get('deposit_amount', 500),
            'slotDuration' => $settings->get('slot_duration_minutes', 30),
            'autoConfirm' => $settings->get('auto_confirm_paid_appointments', false),
        ]);
    }

    public function updateGeneral(UpdateGeneralSettingsRequest $request, HospitalSettings $settings): RedirectResponse
    {
        $settings->for($this->currentHospital());
        $data = $request->validated();
        $settings->set('deposit_amount', $data['deposit_amount']);
        $settings->set('slot_duration_minutes', $data['slot_duration_minutes']);
        $settings->set('auto_confirm_paid_appointments', $data['auto_confirm_paid_appointments'] ?? false);
        $businessHours = $data['business_hours'] ?? [];
        foreach ($businessHours as &$hours) {
            $hours['closed'] = (bool) ($hours['closed'] ?? false);
        }
        unset($hours);
        $settings->set('business_hours', $businessHours);

        return $this->redirectToTab('general', 'General settings saved.');
    }

    public function updateMpesa(UpdateMpesaSettingsRequest $request, HospitalSettings $settings): RedirectResponse
    {
        $provider = 'mpesa';
        $settings->for($this->currentHospital());
        $existing = $settings->credential($provider) ?? [];
        $submitted = $request->validated();

        $merged = [];
        foreach ($existing as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }
        foreach ($submitted as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        if (! empty($merged)) {
            $settings->setCredential($provider, $merged);
        }

        return $this->redirectToTab($provider, 'M-Pesa settings saved.');
    }

    public function updateWhatsApp(UpdateWhatsAppSettingsRequest $request, HospitalSettings $settings): RedirectResponse
    {
        $provider = 'whatsapp';
        $settings->for($this->currentHospital());
        $existing = $settings->credential($provider) ?? [];
        $submitted = $request->validated();

        $merged = [];
        foreach ($existing as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }
        foreach ($submitted as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        if (! empty($merged)) {
            $settings->setCredential($provider, $merged);
        }

        return $this->redirectToTab($provider, 'WhatsApp settings saved.');
    }

    public function updateSms(UpdateSmsSettingsRequest $request, HospitalSettings $settings): RedirectResponse
    {
        $provider = 'sms';
        $settings->for($this->currentHospital());
        $existing = $settings->credential($provider) ?? [];
        $submitted = $request->validated();
        $selectedProvider = $submitted['provider'];
        $activeFields = $selectedProvider === 'africastalking'
            ? ['username', 'api_key', 'sender_id']
            : ['account_sid', 'auth_token', 'from_number'];

        $merged = [];
        if (($existing['provider'] ?? null) === $selectedProvider) {
            foreach ($activeFields as $key) {
                $value = $existing[$key] ?? null;
                if ($value !== null && $value !== '') {
                    $merged[$key] = $value;
                }
            }
        }

        foreach ($submitted as $key => $value) {
            if ($key !== 'provider' && ! in_array($key, $activeFields, true)) {
                continue;
            }
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        if (! empty($merged)) {
            $settings->setCredential($provider, $merged);
        }

        return $this->redirectToTab($provider, 'SMS settings saved.');
    }

    public function updateEmail(UpdateEmailSettingsRequest $request, HospitalSettings $settings): RedirectResponse
    {
        $provider = 'email';
        $settings->for($this->currentHospital());
        $existing = $settings->credential($provider) ?? [];
        $submitted = $request->validated();

        $merged = [];
        foreach ($existing as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }
        foreach ($submitted as $key => $value) {
            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        if (! empty($merged)) {
            $settings->setCredential($provider, $merged);
        }

        return $this->redirectToTab($provider, 'Email settings saved.');
    }

    public function clearCredential(string $provider, HospitalSettings $settings): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $settings->for($this->currentHospital())->deleteCredential($provider);

        return $this->redirectToTab($provider, ucfirst($provider).' credentials cleared.');
    }

    private function currentHospital(): Hospital
    {
        $hospital = hospital();
        abort_unless($hospital instanceof Hospital, 403, 'No hospital is bound to this request.');

        return $hospital;
    }

    private function redirectToTab(string $tab, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.integration-settings.edit', ['tab' => $tab])
            ->with('status', $message);
    }
}
