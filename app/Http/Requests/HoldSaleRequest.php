<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HoldSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create-sale');
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'label' => ['nullable', 'string', 'max:60'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999.999'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['items' => 'keranjang', 'items.*.product_id' => 'produk', 'items.*.quantity' => 'jumlah'];
    }
}
