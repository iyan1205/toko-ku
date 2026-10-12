<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validasi di sini hanya bentuk data. Harga TIDAK divalidasi/dipercaya dari sini — SaleService
 * selalu mengambil ulang harga jual dan harga modal dari database (lihat SaleService::hitung()).
 */
class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create-sale');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'discount' => $this->filled('discount') ? $this->input('discount') : '0',
            'paid_amount' => $this->filled('paid_amount') ? $this->input('paid_amount') : '0',
        ]);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.999', 'decimal:0,3'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],

            'discount' => ['numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'payment_method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::untukPenjualan()))],
            // Tunai: uang diterima. Piutang: bayar di muka (boleh 0). Metode lain: diabaikan (lunas otomatis).
            'paid_amount' => ['numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'dp_method' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::untukPembelian()))],

            'note' => ['nullable', 'string', 'max:500'],
            'held_id' => ['nullable', 'integer'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'pelanggan',
            'items' => 'keranjang',
            'items.*.product_id' => 'produk',
            'items.*.quantity' => 'jumlah',
            'items.*.discount' => 'diskon baris',
            'discount' => 'diskon transaksi',
            'payment_method' => 'metode pembayaran',
            'paid_amount' => 'jumlah dibayar',
            'dp_method' => 'metode bayar di muka',
            'note' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Keranjang masih kosong.',
            'items.min' => 'Keranjang masih kosong.',
            'items.*.product_id.distinct' => 'Produk yang sama tidak boleh muncul dua kali. Gabungkan jumlahnya.',
            'items.*.quantity.decimal' => 'Jumlah maksimal 3 angka di belakang koma.',
            'items.*.discount.decimal' => 'Diskon baris maksimal 2 angka di belakang koma.',
            'discount.decimal' => 'Diskon maksimal 2 angka di belakang koma.',
            'paid_amount.decimal' => 'Jumlah dibayar maksimal 2 angka di belakang koma.',
        ];
    }
}
