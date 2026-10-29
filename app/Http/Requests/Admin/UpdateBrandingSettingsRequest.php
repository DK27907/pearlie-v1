<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\AuthorizesHospitalSettings;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandingSettingsRequest extends FormRequest
{
    use AuthorizesHospitalSettings;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->canManageHospitalSettings();
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
            'site_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,svg,webp', 'extensions:png,jpg,jpeg,svg,webp', 'max:2048'],
        ];
    }
}
