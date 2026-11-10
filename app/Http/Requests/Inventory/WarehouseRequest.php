<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('warehouse') ? 'inventory.warehouses.update' : 'inventory.warehouses.create');
    }

    /** Kode disimpan huruf besar tanpa spasi di tepi. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
            'is_active' => $this->boolean('is_active'),
            'is_default' => $this->boolean('is_default'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Kode gudang yang sudah dihapus tetap tidak boleh dipakai ulang (kartu stok lama merujuknya).
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('warehouses', 'code')->ignore($this->route('warehouse'))],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode gudang',
            'name' => 'nama gudang',
            'address' => 'alamat',
            'branch_id' => 'cabang',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kode gudang hanya boleh berisi huruf, angka, dan tanda -.',
            'code.unique' => 'Kode gudang sudah dipakai (termasuk oleh gudang yang sudah dihapus).',
        ];
    }
}
