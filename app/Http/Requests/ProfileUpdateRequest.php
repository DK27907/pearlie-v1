<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
        ];

        if ($this->user()?->isDoctor()) {
            $rules += [
                'specialization' => ['sometimes', 'required', 'string', 'max:255'],
                'bio' => ['sometimes', 'nullable', 'string', 'max:3000'],
                'consultation_fee' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
                'licence_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            ];
        }

        return $rules;
    }
}
