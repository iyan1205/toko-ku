<?php

namespace App\Http\Requests\Purchasing;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bayar hutang satu faktur. Batas sisa hutang diperiksa PaymentService (dengan penguncian). */
class SupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchasing.payables.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999.99', 'decimal:0,2'],
            'method' => ['required', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::untukPembelian()))],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'amount' => 'jumlah bayar',
            'method' => 'metode pembayaran',
            'paid_at' => 'tanggal bayar',
            'note' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return ['paid_at.before_or_equal' => 'Tanggal bayar tidak boleh lebih dari hari ini.'];
    }
}
