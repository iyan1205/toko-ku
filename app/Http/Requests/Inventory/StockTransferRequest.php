<?php

namespace App\Http\Requests\Inventory;

use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bentuk data saja. Jumlah dan duplikasi produk diperiksa StockTransferService. */
class StockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.transfers.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $activeWarehouse = Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at');

        return [
            'from_warehouse_id' => ['required', 'integer', $activeWarehouse],
            'to_warehouse_id' => ['required', 'integer', 'different:from_warehouse_id', $activeWarehouse],
            'transfer_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'from_warehouse_id' => 'gudang asal',
            'to_warehouse_id' => 'gudang tujuan',
            'transfer_date' => 'tanggal transfer',
            'note' => 'catatan',
            'items' => 'daftar produk',
            'items.*.product_id' => 'produk',
            'items.*.quantity' => 'jumlah',
        ];
    }

    public function messages(): array
    {
        return [
            'to_warehouse_id.different' => 'Gudang tujuan harus berbeda dengan gudang asal.',
            'transfer_date.before_or_equal' => 'Tanggal transfer tidak boleh lebih dari hari ini.',
            'items.required' => 'Tambahkan minimal satu produk.',
        ];
    }
}
