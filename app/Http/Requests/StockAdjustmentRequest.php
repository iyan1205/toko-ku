<?php

namespace App\Http\Requests;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-stock');
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'type' => ['required', Rule::in(array_map(fn ($t) => $t->value, StockMovementType::manual()))],
            'direction' => ['required_if:type,' . StockMovementType::Adjustment->value, 'nullable', Rule::in(['in', 'out'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'note' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'product_id' => 'produk',
            'type' => 'jenis',
            'direction' => 'arah',
            'quantity' => 'jumlah',
            'note' => 'alasan',
        ];
    }

    public function messages(): array
    {
        return [
            'direction.required_if' => 'Pilih apakah stok ditambah atau dikurangi.',
            'note.required' => 'Alasan wajib diisi supaya riwayat stok bisa dipertanggungjawabkan.',
        ];
    }
}
