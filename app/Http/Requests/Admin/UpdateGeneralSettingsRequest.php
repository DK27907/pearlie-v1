<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralSettingsRequest extends FormRequest
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
        return [
            'deposit_amount' => ['required', 'integer', 'min:0', 'max:1000000'],
            'slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'auto_confirm_paid_appointments' => ['sometimes', 'boolean'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.*.open' => ['required_with:business_hours', 'regex:/^\d{2}:\d{2}$/'],
            'business_hours.*.close' => ['required_with:business_hours', 'regex:/^\d{2}:\d{2}$/'],
            'business_hours.*.closed' => ['sometimes', 'boolean'],
        ];
    }
}
