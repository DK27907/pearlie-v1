<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesHospitalSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralSettingsRequest extends FormRequest
{
    use AuthorizesHospitalSettings;

    public function authorize(): bool
    {
        return $this->canManageHospitalSettings();
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'deposit_amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'booking_payment_mode' => ['sometimes', 'required', 'in:mpesa,cash,none'],
            'slot_duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'auto_confirm_paid_appointments' => ['sometimes', 'boolean'],
            'patient_cancellation_enabled' => ['sometimes', 'boolean'],
            'patient_rescheduling_enabled' => ['sometimes', 'boolean'],
            'cancellation_cutoff_hours' => ['sometimes', 'integer', 'between:0,8760'],
            'rescheduling_cutoff_hours' => ['sometimes', 'integer', 'between:0,8760'],
            'late_cancellation_fee' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'deposits_refundable' => ['sometimes', 'boolean'],
            'reminder_enabled' => ['sometimes', 'boolean'],
            'reminder_hours_before' => ['sometimes', 'integer', 'between:1,168'],
            'reminder_message_template' => ['sometimes', 'string', 'max:1000'],
            'business_hours' => ['nullable', 'array'],
            'business_hours.*.open' => ['required_with:business_hours', 'regex:/^\d{2}:\d{2}$/'],
            'business_hours.*.close' => ['required_with:business_hours', 'regex:/^\d{2}:\d{2}$/'],
            'business_hours.*.closed' => ['sometimes', 'boolean'],
        ];
    }
}
