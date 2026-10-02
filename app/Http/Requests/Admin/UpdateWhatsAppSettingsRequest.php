<?php

namespace App\Http\Requests\Admin;

use App\Services\HospitalSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppSettingsRequest extends FormRequest
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
        $stored = app(HospitalSettings::class)->for(hospital())->credential('whatsapp') ?? [];
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
