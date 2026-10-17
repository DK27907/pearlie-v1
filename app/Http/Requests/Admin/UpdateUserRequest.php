<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(['hospital_admin', 'doctor', 'patient'])],
            'is_active' => ['required', 'boolean'],
            'password' => ['nullable', Password::defaults(), 'confirmed'],
            'password_confirmation' => ['required_with:password', 'nullable', 'string'],
            'must_change_password' => ['sometimes', 'boolean'],
        ];
    }
}
