<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Retur pembelian atas satu faktur. items = purchase_item_id => jumlah diretur.
 * aksi=approve langsung menyetujui dan butuh izin purchasing.returns.approve.
 */
class PurchaseReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchasing.returns.create')
            && ($this->input('aksi') !== 'approve' || $this->user()->can('purchasing.returns.approve'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'aksi' => ['required', Rule::in(['draft', 'approve'])],
            'purchase_id' => ['required', 'integer', Rule::exists('purchases', 'id')->where('status', 'completed')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'return_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0', 'max:99999.999', 'decimal:0,3'],
        ];
    }

    public function attributes(): array
    {
        return [
            'purchase_id' => 'faktur',
            'warehouse_id' => 'gudang',
            'return_date' => 'tanggal retur',
            'reason' => 'alasan retur',
            'items.*' => 'jumlah retur',
        ];
    }

    public function messages(): array
    {
        return [
            'purchase_id.exists' => 'Faktur tidak ditemukan atau sudah dibatalkan.',
            'return_date.before_or_equal' => 'Tanggal retur tidak boleh lebih dari hari ini.',
        ];
    }
}
