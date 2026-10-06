<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('stock-opname');
    }

    public function rules(): array
    {
        return [
            'opname_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.system_stock' => ['required', 'numeric'],
            'items.*.physical_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'opname_date' => 'tanggal opname',
            'note' => 'catatan',
            'items' => 'daftar produk',
            'items.*.physical_stock' => 'stok fisik',
            'items.*.reason' => 'alasan selisih',
        ];
    }

    public function messages(): array
    {
        return [
            'opname_date.before_or_equal' => 'Tanggal opname tidak boleh lebih dari hari ini.',
            'items.*.physical_stock.min' => 'Stok fisik tidak boleh negatif.',
        ];
    }
}
