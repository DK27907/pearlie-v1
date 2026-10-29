<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesHospitalSettings;
use App\Services\HospitalSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSmsSettingsRequest extends FormRequest
{
    use AuthorizesHospitalSettings;

    public function authorize(): bool
    {
        return $this->canManageHospitalSettings();
    }

    public function rules(): array
    {
        $provider = $this->input('provider');
        $stored = app(HospitalSettings::class)->for(hospital())->credentialForAdminForm('sms')['credentials'];
        $conditionalRules = static function (string $key, array $rules) use ($stored): array {
            $providerRule = 'required_if:provider,'.($key === 'username' || $key === 'api_key' || $key === 'sender_id'
                ? 'africastalking'
                : 'twilio');

            return filled($stored[$key] ?? null)
                ? ['nullable', 'string', ...$rules]
                : [$providerRule, 'nullable', 'string', ...$rules];
        };

        return [
            'provider' => ['required', 'in:africastalking,twilio'],
            'username' => $conditionalRules('username', ['max:120']),
            'api_key' => $conditionalRules('api_key', ['max:255']),
            'sender_id' => ['nullable', 'string', 'max:20'],
            'account_sid' => $conditionalRules('account_sid', ['max:100']),
            'auth_token' => $conditionalRules('auth_token', ['max:255']),
            'from_number' => $conditionalRules('from_number', ['max:30']),
        ];
    }
}
