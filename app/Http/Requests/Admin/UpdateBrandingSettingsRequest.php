<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
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

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chatbot_name' => ['nullable', 'string', 'max:60'],
            'site_header_text' => ['nullable', 'string', 'max:120'],
            'site_footer_text' => ['nullable', 'string', 'max:2000'],
            'site_logo' => ['nullable', 'image:allow_svg', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
        ];
    }
}
