<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateBrandingSettingsRequest;
use App\Http\Requests\Admin\UpdateEmailSettingsRequest;
use App\Http\Requests\Admin\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\UpdateMpesaSettingsRequest;
use App\Http\Requests\Admin\UpdateSmsSettingsRequest;
use App\Http\Requests\Admin\UpdateWhatsAppSettingsRequest;
use App\Models\Announcement;
use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use App\Services\HospitalSettings;
use App\Services\SmsService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\File\UploadedFile;

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

    public function edit(Request $request, HospitalSettings $settings, SmsService $sms): View
    {
        $hospital = $this->currentHospital();
        $settings->for($hospital);
        $activeTab = $request->query('tab', 'general');
        if (! in_array($activeTab, ['general', ...self::PROVIDERS, 'branding', 'receipts'], true)) {
            $activeTab = 'general';
        }

        $credentials = array_fill_keys(self::PROVIDERS, []);
        if (in_array($activeTab, self::PROVIDERS, true)) {
            $credentialResult = $settings->credentialForAdminForm($activeTab);
            $credentials[$activeTab] = $credentialResult['credentials'];
            if ($credentialResult['unreadable']) {
                session()->flash(
                    'warning',
                    'Stored integration credentials are unreadable. Please re-enter them to restore the integration.',
                );
            }

            foreach (self::SECRET_FIELDS[$activeTab] as $field) {
                if (filled($credentials[$activeTab][$field] ?? null)) {
                    $credentials[$activeTab][$field] = '••••••••'.substr($credentials[$activeTab][$field], -4);
                }
            }
        }

        $canSendReminderTest = false;
        if ($activeTab === 'general') {
            try {
                $canSendReminderTest = $sms->isConfigured() && filled($request->user()?->phone);
            } catch (DecryptException) {
                session()->flash(
                    'warning',
                    'Stored SMS credentials are unreadable. Re-enter them in the SMS settings tab before sending a test.',
                );
            }
        }

        return view('admin.settings.edit', [
            'activeTab' => $activeTab,
            'hospital' => $hospital,
            'credentials' => $credentials,
            'settings' => $settings,
            'businessHours' => $settings->get('business_hours', []),
            'depositAmount' => $settings->get('deposit_amount', 500),
            'slotDuration' => $settings->get('slot_duration_minutes', 30),
            'autoConfirm' => $settings->get('auto_confirm_paid_appointments', true),
            'patientCancellationEnabled' => $hospital->patient_cancellation_enabled,
            'patientReschedulingEnabled' => $hospital->patient_rescheduling_enabled,
            'cancellationCutoffHours' => $hospital->cancellation_cutoff_hours,
            'reschedulingCutoffHours' => $hospital->rescheduling_cutoff_hours,
            'lateCancellationFee' => $hospital->late_cancellation_fee,
            'depositsRefundable' => $hospital->deposits_refundable,
            'reminderHoursBefore' => $hospital->reminder_hours_before ?? 24,
            'canSendReminderTest' => $canSendReminderTest,
            'reminderMessageTemplate' => $hospital->reminder_message_template ?: Hospital::DEFAULT_REMINDER_MESSAGE_TEMPLATE,
        ]);
    }

    public function updateGeneral(UpdateGeneralSettingsRequest $request, HospitalSettings $settings): RedirectResponse
    {
        $hospital = $this->currentHospital();
        $settings->for($hospital);
        $data = $request->validated();
        $settings->set('deposit_amount', $data['deposit_amount']);
        $settings->set('slot_duration_minutes', $data['slot_duration_minutes']);
        $settings->set('auto_confirm_paid_appointments', $data['auto_confirm_paid_appointments'] ?? false);
        $hospitalFields = array_intersect_key($data, array_flip([
            'name',
            'phone',
            'address',
            'booking_payment_mode',
            'patient_cancellation_enabled',
            'patient_rescheduling_enabled',
            'cancellation_cutoff_hours',
            'rescheduling_cutoff_hours',
            'late_cancellation_fee',
            'deposits_refundable',
        ]));
        $hospitalFields['reminder_enabled'] = (bool) ($data['reminder_enabled'] ?? false);
        if (array_key_exists('reminder_hours_before', $data)) {
            $hospitalFields['reminder_hours_before'] = (int) $data['reminder_hours_before'];
        }
        if (array_key_exists('reminder_message_template', $data)) {
            $hospitalFields['reminder_message_template'] = $data['reminder_message_template'];
        }
        if ($hospitalFields !== []) {
            $hospital->fill($hospitalFields)->save();
        }
        $businessHours = $data['business_hours'] ?? [];
        foreach ($businessHours as &$hours) {
            $hours['closed'] = (bool) ($hours['closed'] ?? false);
        }
        unset($hours);
        $settings->set('business_hours', $businessHours);

        return $this->redirectToTab('general', 'General settings saved.');
    }

    public function testReminder(Request $request, SmsService $sms): RedirectResponse
    {
        $hospital = $this->currentHospital();
        $user = $request->user();

        try {
            $smsConfigured = $sms->isConfigured();
        } catch (DecryptException) {
            return $this->redirectToTab('general', 'Stored SMS credentials are unreadable. Re-enter them in SMS settings.', 'error');
        }

        if (! $smsConfigured) {
            return $this->redirectToTab('general', 'Configure a valid SMS provider before sending a test.', 'error');
        }

        if (! $user || blank($user->phone)) {
            return $this->redirectToTab('general', 'Add a phone number to your admin account before sending a test.', 'error');
        }

        $message = (new Announcement([
            'message' => $hospital->reminder_message_template ?: Hospital::DEFAULT_REMINDER_MESSAGE_TEMPLATE,
        ]))->renderFor(
            $user->name,
            'Dr. Example',
            now()->addDay()->format('d M Y g:i A'),
            [
                'booking_reference' => 'BK-TEST-REFERENCE',
                'hospital_name' => $hospital->name,
                'service_name' => 'Sample service',
            ],
        );
        $result = $sms->send((string) $user->phone, $message, 'configured');

        return $this->redirectToTab(
            'general',
            $result->success ? 'Test reminder SMS sent to your admin phone.' : 'Test reminder SMS could not be delivered.',
            $result->success ? 'status' : 'error',
        );
    }

    public function updateMpesa(UpdateMpesaSettingsRequest $request, HospitalSettings $settings): RedirectResponse
    {
        $provider = 'mpesa';
        $settings->for($this->currentHospital());
        $existing = $settings->credentialForAdminForm($provider)['credentials'];
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
        $existing = $settings->credentialForAdminForm($provider)['credentials'];
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
        $existing = $settings->credentialForAdminForm($provider)['credentials'];
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
        $existing = $settings->credentialForAdminForm($provider)['credentials'];
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

    public function updateBranding(UpdateBrandingSettingsRequest $request): RedirectResponse
    {
        $hospital = $this->currentHospital();
        $data = $request->validated();
        $updates = [
            'chatbot_name' => $data['chatbot_name'] ?? null,
            'site_header_text' => $data['site_header_text'] ?? null,
            'site_footer_text' => $data['site_footer_text'] ?? null,
        ];

        if ($request->hasFile('site_logo')) {
            $logo = $request->file('site_logo');
            $extension = strtolower($logo->getClientOriginalExtension());
            $logoPath = "hospitals/{$hospital->slug}/logo.{$extension}";
            $disk = Storage::disk('public');
            $stored = $extension === 'svg'
                ? $disk->put($logoPath, $this->sanitizeSvg($logo))
                : $logo->storeAs("hospitals/{$hospital->slug}", "logo.{$extension}", 'public');

            if ($stored === false) {
                throw new \RuntimeException('The hospital logo could not be stored.');
            }

            $previousLogoPath = $hospital->site_logo_path;
            $updates['site_logo_path'] = $logoPath;
            $hospital->update($updates);

            if ($previousLogoPath !== null && $previousLogoPath !== $logoPath) {
                if (! $disk->delete($previousLogoPath) && $disk->exists($previousLogoPath)) {
                    Log::warning('A replaced hospital logo could not be removed from public storage.', [
                        'hospital_id' => $hospital->id,
                        'path' => $previousLogoPath,
                    ]);
                }
            }
        } else {
            $hospital->update($updates);
        }

        return $this->redirectToTab('branding', 'Branding settings saved.');
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

    private function redirectToTab(string $tab, string $message, string $flashType = 'status'): RedirectResponse
    {
        return redirect()
            ->route('admin.integration-settings.edit', ['tab' => $tab])
            ->with($flashType, $message);
    }

    private function sanitizeSvg(UploadedFile $logo): string
    {
        $previousErrorHandling = libxml_use_internal_errors(true);
        $document = new \DOMDocument('1.0', 'UTF-8');

        try {
            $loaded = $document->loadXML($logo->get(), LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorHandling);
        }

        $root = $document->documentElement;
        if (! $loaded
            || $document->doctype !== null
            || ! $root instanceof \DOMElement
            || $root->localName !== 'svg'
            || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            throw ValidationException::withMessages([
                'site_logo' => 'The site logo must be a valid SVG image.',
            ]);
        }

        $allowedElements = [
            'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline',
            'polygon', 'rect', 'text', 'tspan', 'defs', 'linearGradient',
            'radialGradient', 'stop', 'clipPath', 'mask', 'title', 'desc',
        ];
        $allowedAttributes = [
            'xmlns', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2',
            'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule',
            'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
            'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity',
            'transform', 'offset', 'stop-color', 'stop-opacity', 'clip-path', 'mask',
            'id', 'preserveAspectRatio', 'font-size', 'font-family', 'font-weight',
            'text-anchor', 'dominant-baseline',
        ];

        for ($index = $document->getElementsByTagName('*')->length - 1; $index >= 0; $index--) {
            $element = $document->getElementsByTagName('*')->item($index);
            if (! $element instanceof \DOMElement) {
                continue;
            }

            if (! in_array($element->localName, $allowedElements, true)) {
                $element->parentNode?->removeChild($element);

                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = $attribute->nodeName;
                $value = trim($attribute->nodeValue ?? '');
                $isSafeLocalReference = $name === 'href' && str_starts_with($value, '#');
                $containsExternalReference = preg_match('/(?:javascript:|data:|url\\((?!\\s*[\\\'"]?#))/i', $value) === 1;

                if ((! in_array($name, $allowedAttributes, true) && ! $isSafeLocalReference)
                    || $containsExternalReference) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }

        $sanitized = $document->saveXML($root);
        if (! is_string($sanitized)) {
            throw new \RuntimeException('The hospital SVG logo could not be sanitized.');
        }

        return $sanitized;
    }
}
