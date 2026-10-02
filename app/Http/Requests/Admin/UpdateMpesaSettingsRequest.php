<?php

namespace App\Http\Requests\Admin;

use App\Services\HospitalSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMpesaSettingsRequest extends FormRequest
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
        $stored = app(HospitalSettings::class)->for(hospital())->credential('mpesa') ?? [];
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
