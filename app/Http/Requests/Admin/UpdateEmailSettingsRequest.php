<?php

namespace App\Http\Requests\Admin;

use App\Services\HospitalSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $hospital = hospital();

        return $user !== null
            && $user->isAdmin()
            && ! $user->isSuperAdmin()
            && ! $user->isDoctor()
            && $hospital !== null
            && (int) $user->hospital_id === (int) $hospital->id;
    }

    public function rules(): array
    {
        $stored = app(HospitalSettings::class)->for(hospital())->credential('email') ?? [];
        $resendKeyRules = filled($stored['resend_api_key'] ?? null)
            ? ['sometimes', 'nullable', 'string', 'max:255']
            : ['required_if:mailer,resend', 'nullable', 'string', 'max:255'];
        $smtpPasswordRules = filled($stored['smtp_password'] ?? null)
            ? ['sometimes', 'nullable', 'string', 'max:255']
            : ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'];

        return [
            'mailer' => ['required', 'in:resend,smtp,log'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'resend_api_key' => $resendKeyRules,
            'smtp_host' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'smtp_port' => ['required_if:mailer,smtp', 'nullable', 'integer', 'between:1,65535'],
            'smtp_username' => ['required_if:mailer,smtp', 'nullable', 'string', 'max:255'],
            'smtp_password' => $smtpPasswordRules,
            'smtp_encryption' => ['required_if:mailer,smtp', 'nullable', 'in:tls,ssl,none'],
        ];
    }
}
