<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan purchase order. aksi=draft menyimpan saja; aksi=approve langsung menyetujui dan
 * butuh izin purchasing.orders.approve. Angka dihitung ulang oleh PurchaseOrderService.
 */
class PurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $base = $this->route('purchaseOrder') ? 'purchasing.orders.update' : 'purchasing.orders.create';

        return $user->can($base) && ($this->input('aksi') !== 'approve' || $user->can('purchasing.orders.approve'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'discount' => $this->filled('discount') ? $this->input('discount') : '0',
            'tax' => $this->filled('tax') ? $this->input('tax') : '0',
            'other_cost' => $this->filled('other_cost') ? $this->input('other_cost') : '0',
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uang = ['numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'];

        return [
            'aksi' => ['required', Rule::in(['draft', 'approve'])],
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'note' => ['nullable', 'string', 'max:1000'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.999', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],

            'discount' => $uang,
            'tax' => $uang,
            'other_cost' => $uang,
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'warehouse_id' => 'gudang tujuan',
            'order_date' => 'tanggal PO',
            'expected_date' => 'perkiraan tiba',
            'items' => 'daftar barang',
            'items.*.product_id' => 'produk',
            'items.*.quantity' => 'jumlah',
            'items.*.unit_price' => 'harga',
            'items.*.discount' => 'diskon baris',
            'discount' => 'diskon',
            'tax' => 'pajak',
            'other_cost' => 'biaya lain',
        ];
    }

    public function messages(): array
    {
        return ['items.required' => 'Tambahkan minimal satu produk.'];
    }
}
