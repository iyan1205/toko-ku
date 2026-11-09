<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\StoreProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Isian form Struk & Printer yang belum disimpan, untuk pratinjau. Semua opsional. */
class ReceiptPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.receipts.view');
    }

    protected function prepareForValidation(): void
    {
        foreach (['show_logo', 'show_npwp'] as $flag) {
            if ($this->has($flag)) {
                $this->merge([$flag => $this->boolean($flag)]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'paper_width' => ['nullable', 'integer', Rule::in(StoreProfileService::LEBAR_DIDUKUNG)],
            'footer' => ['nullable', 'string', 'max:255'],
            'show_logo' => ['nullable', 'boolean'],
            'show_npwp' => ['nullable', 'boolean'],
        ];
    }
}
