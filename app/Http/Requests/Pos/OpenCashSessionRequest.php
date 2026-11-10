<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Buka sesi kasir: gudang penjualan dan modal awal (uang di laci saat mulai). */
class OpenCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.sessions.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['opening_cash' => $this->filled('opening_cash') ? $this->input('opening_cash') : '0']);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'opening_cash' => ['numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'opening_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['warehouse_id' => 'gudang penjualan', 'opening_cash' => 'modal awal', 'opening_note' => 'catatan'];
    }
}
