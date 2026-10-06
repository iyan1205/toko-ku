<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        $produk = $this->route('product');

        return $produk
            ? $this->user()->can('update', $produk)
            : $this->user()->can('create', Product::class);
    }

    protected function prepareForValidation(): void
    {
        $sku = trim((string) $this->input('sku'));
        $barcode = trim((string) $this->input('barcode'));

        $this->merge([
            'sku' => $sku === '' ? null : strtoupper($sku),
            'barcode' => $barcode === '' ? null : $barcode,
            'discount_percent' => $this->filled('discount_percent') ? $this->input('discount_percent') : 0,
            'min_stock' => $this->filled('min_stock') ? $this->input('min_stock') : 0,
            'is_active' => $this->boolean('is_active'),
            'remove_photo' => $this->boolean('remove_photo'),
        ]);
    }

    public function rules(): array
    {
        $id = $this->route('product')?->id;

        return [
            // Unik terhadap SEMUA baris, termasuk produk yang sudah dihapus (kolom di database memang unik).
            'sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9._\-\/]+$/', Rule::unique('products', 'sku')->ignore($id)],
            'barcode' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-]+$/', Rule::unique('products', 'barcode')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'unit_id' => ['required', 'integer', 'exists:units,id'],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'min_stock' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'discount_percent' => ['required', 'numeric', 'between:0,100'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_photo' => ['boolean'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'barcode' => 'barcode',
            'name' => 'nama produk',
            'category_id' => 'kategori',
            'unit_id' => 'satuan',
            'purchase_price' => 'harga beli',
            'selling_price' => 'harga jual',
            'min_stock' => 'stok minimum',
            'discount_percent' => 'diskon',
            'photo' => 'foto produk',
            'description' => 'deskripsi',
        ];
    }

    public function messages(): array
    {
        return [
            'sku.unique' => 'SKU ini sudah dipakai produk lain (termasuk produk yang sudah dihapus).',
            'sku.regex' => 'SKU hanya boleh berisi huruf, angka, titik, strip, garis miring, dan garis bawah.',
            'barcode.unique' => 'Barcode ini sudah dipakai produk lain.',
            'barcode.regex' => 'Barcode hanya boleh berisi huruf, angka, titik, strip, dan garis bawah.',
            'photo.max' => 'Ukuran foto produk maksimal 2 MB.',
            'photo.image' => 'Foto produk harus berupa gambar.',
            'photo.mimes' => 'Foto produk harus berformat JPG, PNG, atau WEBP.',
        ];
    }
}
