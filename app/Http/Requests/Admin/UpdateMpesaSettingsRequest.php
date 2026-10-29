<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesHospitalSettings;
use App\Services\HospitalSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMpesaSettingsRequest extends FormRequest
{
    use AuthorizesHospitalSettings;

    public function authorize(): bool
    {
        return $this->canManageHospitalSettings();
    }

    public function rules(): array
    {
        $stored = app(HospitalSettings::class)->for(hospital())->credentialForAdminForm('mpesa')['credentials'];
        $secretRules = static fn (string $key, array $rules): array => filled($stored[$key] ?? null)
            ? ['sometimes', 'nullable', ...$rules]
            : ['required', ...$rules];

        return [
            'consumer_key' => $secretRules('consumer_key', ['string', 'max:255']),
            'consumer_secret' => $secretRules('consumer_secret', ['string', 'max:255']),
            'shortcode' => ['required', 'string', 'max:20'],
            'passkey' => $secretRules('passkey', ['string', 'max:255']),
            'environment' => ['required', 'in:sandbox,production'],
            'callback_url' => ['required', 'url', 'max:255'],
        ];
    }
}
