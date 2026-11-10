<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class AccountingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.accounting.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['auto_journal' => $this->boolean('auto_journal')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['auto_journal' => ['boolean']];
    }
}
