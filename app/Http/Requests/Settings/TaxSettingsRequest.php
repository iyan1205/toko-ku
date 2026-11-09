<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class TaxSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.taxes.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'enabled' => $this->boolean('enabled'),
            'inclusive' => $this->boolean('inclusive'),
            // Tarif boleh diketik dengan koma desimal: "11,5".
            'rate' => is_string($this->input('rate')) ? str_replace(',', '.', trim($this->input('rate'))) : $this->input('rate'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'name' => ['required', 'string', 'max:20'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'inclusive' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama pajak', 'rate' => 'tarif pajak'];
    }
}
