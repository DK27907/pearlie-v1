<?php

use App\Models\Hospital;
use Illuminate\Support\Facades\Storage;

if (! function_exists('hospital')) {
    function hospital(): ?Hospital
    {
        return app()->bound('currentHospital')
            ? app('currentHospital')
            : null;
    }
}

if (! function_exists('pearlie_config')) {
    function pearlie_config(string $key, mixed $default = null): mixed
    {
        $hospital = hospital();
        $value = null;

        if ($hospital && str_starts_with($key, 'hospital.')) {
            $field = substr($key, strlen('hospital.'));
            $field = match ($field) {
                'location' => 'address',
                'appointment_phone' => 'phone',
                default => $field,
            };
            $value = $hospital->getAttribute($field);
        } elseif ($hospital && $key === 'appointment.deposit_amount') {
            $value = $hospital->deposit_amount;
        } elseif ($hospital && $key === 'appointment.slot_duration_minutes') {
            $value = $hospital->slot_duration_minutes;
        } elseif ($hospital && $key === 'ai.default_language') {
            $value = $hospital->default_language;
        } elseif ($hospital && $key === 'ai.supported_languages') {
            $value = $hospital->supported_languages;
        } elseif ($hospital) {
            $value = data_get($hospital->settings, $key);
        }

        return $value ?? config('pearlie.'.$key, $default);
    }
}

if (! function_exists('hospital_branding')) {
    /**
     * @return array{header_text: string, footer_text: string, logo_url: ?string}
     */
    function hospital_branding(): array
    {
        $tenantHospital = hospital();
        $hospitalName = $tenantHospital?->name ?? 'MediDesk AI';
        $defaultFooter = '© '.date('Y').' '.$hospitalName;
        if (filled($tenantHospital?->address)) {
            $defaultFooter .= ' · '.$tenantHospital->address;
        }
        $logoPath = $tenantHospital?->site_logo_path ?: $tenantHospital?->logo_url;

        return [
            'header_text' => filled($tenantHospital?->site_header_text)
                ? $tenantHospital->site_header_text
                : $hospitalName,
            'footer_text' => filled($tenantHospital?->site_footer_text)
                ? $tenantHospital->site_footer_text
                : $defaultFooter,
            'logo_url' => $logoPath ? Storage::disk('public')->url($logoPath) : null,
        ];
    }
}
