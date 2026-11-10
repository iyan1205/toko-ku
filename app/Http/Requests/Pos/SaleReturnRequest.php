<?php

namespace App\Http\Requests\Pos;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajukan retur penjualan. items = sale_item_id => jumlah diretur. Sisa yang bisa diretur
 * diperiksa SaleReturnService. aksi=approve (hanya pemegang pos.returns.approve) langsung menyetujui.
 */
class SaleReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pos.returns.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0', 'max:99999.999', 'decimal:0,3'],
            'aksi' => ['nullable', Rule::in(['draft', 'approve'])],
            'refund_method' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::untukPembelian()))],
        ];
    }

    public function attributes(): array
    {
        return ['reason' => 'alasan retur', 'items' => 'barang', 'items.*' => 'jumlah retur', 'refund_method' => 'metode pengembalian uang'];
    }

    public function messages(): array
    {
        return ['items.*.decimal' => 'Jumlah retur maksimal 3 angka di belakang koma.'];
    }
}
