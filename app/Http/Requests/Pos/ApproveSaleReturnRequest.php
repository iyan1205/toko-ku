<?php

namespace App\Http\Requests\Pos;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Setujui retur penjualan: metode pengembalian uang (bila ada uang yang dikembalikan). */
class ApproveSaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.returns.approve');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'refund_method' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::untukPembelian()))],
        ];
    }

    public function attributes(): array
    {
        return ['refund_method' => 'metode pengembalian uang'];
    }
}
