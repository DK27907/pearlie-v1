<?php

use App\Models\Hospital;

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

            return $hospital->getAttribute($field) ?? $default;
        }

        if ($hospital && $key === 'appointment.deposit_amount') {
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
     * @return array{header_text: string, footer_text: string, logo_url: ?string, primary_color: string, secondary_color: string}
     */
    function hospital_branding(): array
    {
        $tenantHospital = hospital();
        if (! $tenantHospital) {
            return platform_branding();
        }

        $hospitalName = $tenantHospital->name;
        $defaultFooter = '© '.date('Y').' '.$hospitalName;
        if (filled($tenantHospital->address)) {
            $defaultFooter .= ' · '.$tenantHospital->address;
        }

        return [
            'header_text' => filled($tenantHospital->site_header_text)
                ? $tenantHospital->site_header_text
                : $hospitalName,
            'footer_text' => filled($tenantHospital?->site_footer_text)
                ? $tenantHospital->site_footer_text
                : $defaultFooter,
            'logo_url' => $tenantHospital->logo_url,
            'primary_color' => $tenantHospital->primary_color ?: '#0a2f44',
            'secondary_color' => $tenantHospital->secondary_color ?: '#1a5276',
        ];
    }
}

if (! function_exists('tenant_branding')) {
    /**
     * @return array{header_text: string, footer_text: string, logo_url: ?string, primary_color: string, secondary_color: string}|array{name: string, tagline: ?string, logo_url: ?string, phone: ?string, address: ?string, hours: array<string, mixed>|string|null, is_tenant: bool}
     */
    function tenant_branding(?Hospital $hospital): array
    {
        if (! $hospital) {
            return platform_branding();
        }

        return [
            'name' => $hospital->name,
            'tagline' => null,
            'logo_url' => $hospital->logo_url,
            'phone' => $hospital->phone,
            'address' => $hospital->address,
            'hours' => filled($hospital->business_hours)
                ? $hospital->business_hours
                : ($hospital->hours_outpatient ?: null),
            'is_tenant' => true,
        ];
    }
}

if (! function_exists('platform_branding')) {
    /**
     * @return array{header_text: string, footer_text: string, logo_url: ?string, primary_color: string, secondary_color: string}
     */
    function platform_branding(): array
    {
        return [
            'header_text' => (string) config('app.platform_name', 'MediDesk AI'),
            'footer_text' => (string) config('app.platform_footer', 'MediDesk AI — AxiomForge Digital Solutions'),
            'logo_url' => config('app.platform_logo'),
            'primary_color' => '#0a2f44',
            'secondary_color' => '#1a5276',
        ];
    }
}
