<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\StoreProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiptSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.receipts.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'auto_print' => $this->boolean('auto_print'),
            'show_logo' => $this->boolean('show_logo'),
            'show_npwp' => $this->boolean('show_npwp'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'paper_width' => ['required', 'integer', Rule::in(StoreProfileService::LEBAR_DIDUKUNG)],
            'footer' => ['nullable', 'string', 'max:255'],
            'auto_print' => ['boolean'],
            'show_logo' => ['boolean'],
            'show_npwp' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['paper_width' => 'lebar kertas struk', 'footer' => 'footer struk'];
    }

    public function messages(): array
    {
        return ['paper_width.in' => 'Lebar kertas struk harus 58 mm atau 80 mm.'];
    }
}
