<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('costCenter') ? 'accounting.cost-centers.update' : 'accounting.cost-centers.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Kode cost center yang sudah dihapus tetap tidak boleh dipakai ulang (jurnal lama merujuknya).
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Z.\-_]+$/', Rule::unique('cost_centers', 'code')->ignore($this->route('costCenter')?->id)],
            'name' => ['required', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'kode', 'name' => 'nama', 'department_id' => 'departemen', 'description' => 'deskripsi'];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya boleh berisi huruf, angka, titik, garis bawah, dan tanda -.',
            'code.unique' => 'Kode ini sudah dipakai (termasuk oleh cost center yang sudah dihapus).',
        ];
    }
}
