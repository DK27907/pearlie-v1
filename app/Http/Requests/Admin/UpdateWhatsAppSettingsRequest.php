<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesHospitalSettings;
use App\Services\HospitalSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppSettingsRequest extends FormRequest
{
    use AuthorizesHospitalSettings;

    public function authorize(): bool
    {
        return $this->canManageHospitalSettings();
    }

    public function rules(): array
    {
        $stored = app(HospitalSettings::class)->for(hospital())->credentialForAdminForm('whatsapp')['credentials'];
        $secretRules = static fn (string $key, array $rules): array => filled($stored[$key] ?? null)
            ? ['sometimes', 'nullable', ...$rules]
            : ['required', ...$rules];

        return [
            'phone_number_id' => ['required', 'string', 'max:64'],
            'access_token' => $secretRules('access_token', ['string', 'max:2000']),
            'verify_token' => $secretRules('verify_token', ['string', 'max:255']),
            'app_secret' => $secretRules('app_secret', ['string', 'max:255']),
            'api_version' => ['required', 'string', 'max:10'],
        ];
    }
}
