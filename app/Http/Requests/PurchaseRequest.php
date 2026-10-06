<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Purchase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create-purchase');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'discount' => $this->filled('discount') ? $this->input('discount') : '0',
            'tax' => $this->filled('tax') ? $this->input('tax') : '0',
            'other_cost' => $this->filled('other_cost') ? $this->input('other_cost') : '0',
            'paid_amount' => $this->filled('paid_amount') ? $this->input('paid_amount') : '0',
            'update_cost' => $this->boolean('update_cost'),
        ]);
    }

    public function rules(): array
    {
        $uang = ['numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'];

        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->whereNull('deleted_at')],
            // Faktur yang sama dari supplier yang sama hanya boleh tercatat sekali (pembelian yang sudah dibatalkan tidak dihitung).
            'supplier_invoice_number' => [
                'nullable', 'string', 'max:50',
                Rule::unique('purchases', 'supplier_invoice_number')->where(
                    fn ($q) => $q->where('supplier_id', $this->input('supplier_id'))->where('status', 'completed')
                ),
            ],
            'purchase_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:purchase_date'],

            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.999', 'decimal:0,3'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],

            'discount' => $uang,
            'tax' => $uang,
            'other_cost' => $uang,
            'paid_amount' => $uang,
            'payment_method' => [
                'nullable',
                Rule::requiredIf(fn () => (float) $this->input('paid_amount') > 0),
                Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::untukPembelian())),
            ],
            'update_cost' => ['boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id' => 'supplier',
            'supplier_invoice_number' => 'nomor faktur supplier',
            'purchase_date' => 'tanggal pembelian',
            'due_date' => 'jatuh tempo',
            'items' => 'daftar produk',
            'items.*.product_id' => 'produk',
            'items.*.quantity' => 'jumlah',
            'items.*.unit_price' => 'harga beli',
            'items.*.discount' => 'diskon baris',
            'discount' => 'diskon',
            'tax' => 'pajak',
            'other_cost' => 'biaya lain',
            'paid_amount' => 'jumlah dibayar',
            'payment_method' => 'metode pembayaran',
            'note' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Tambahkan minimal satu produk.',
            'items.min' => 'Tambahkan minimal satu produk.',
            'items.max' => 'Satu pembelian maksimal 100 baris produk.',
            'items.*.product_id.distinct' => 'Produk yang sama tidak boleh muncul dua kali. Gabungkan jumlahnya.',
            'items.*.quantity.decimal' => 'Jumlah maksimal 3 angka di belakang koma.',
            'items.*.quantity.max' => 'Jumlah maksimal 99.999,999 per baris.',
            'items.*.unit_price.decimal' => 'Harga beli maksimal 2 angka di belakang koma.',
            'items.*.unit_price.max' => 'Harga beli maksimal Rp 99.999.999,99 per unit.',
            'items.*.discount.decimal' => 'Diskon baris maksimal 2 angka di belakang koma.',
            'discount.decimal' => 'Diskon maksimal 2 angka di belakang koma.',
            'tax.decimal' => 'Pajak maksimal 2 angka di belakang koma.',
            'other_cost.decimal' => 'Biaya lain maksimal 2 angka di belakang koma.',
            'paid_amount.decimal' => 'Jumlah dibayar maksimal 2 angka di belakang koma.',
            'supplier_invoice_number.unique' => 'Nomor faktur ini sudah tercatat untuk supplier tersebut.',
            'purchase_date.before_or_equal' => 'Tanggal pembelian tidak boleh lebih dari hari ini.',
            'due_date.after_or_equal' => 'Jatuh tempo tidak boleh sebelum tanggal pembelian.',
            'payment_method.required' => 'Pilih metode pembayaran.',
        ];
    }
}
