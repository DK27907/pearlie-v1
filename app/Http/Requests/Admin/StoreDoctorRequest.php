<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')],
            'specialization' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9\s().-]{7,30}$/'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'consultation_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'licence_number' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
