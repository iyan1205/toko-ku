<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Dijual di kasir = type jual; tidak dijual = bahan baku.
            'type' => $this->has('is_sellable') ? ($this->boolean('is_sellable') ? 'jual' : 'bahan_baku') : ($this->route('product')?->type ?? 'jual'),
            'is_purchasable' => $this->has('is_purchasable') ? $this->boolean('is_purchasable') : ($this->route('product')?->is_purchasable ?? true),
            'track_batches' => $this->boolean('track_batches'),
            'track_serials' => $this->boolean('track_serials'),
            'is_bundle' => $this->boolean('is_bundle'),
            'remove_photo' => $this->boolean('remove_photo'),
            'units' => array_values(array_filter((array) $this->input('units', []), fn ($u) => is_array($u) && filled($u['unit_id'] ?? null))),
            // Atribut varian: nilai ditulis dipisah koma ("S, M, L").
            'variant_options' => array_values(array_filter(array_map(fn ($o) => is_array($o) ? [
                'name' => trim((string) ($o['name'] ?? '')),
                'values' => array_values(array_unique(array_filter(array_map('trim', is_array($o['values'] ?? null) ? $o['values'] : explode(',', (string) ($o['values'] ?? '')))))),
            ] : null, (array) $this->input('variant_options', [])), fn ($o) => $o && $o['name'] !== '' && $o['values'] !== [])),
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
            'type' => ['required', Rule::in(['jual', 'bahan_baku'])],
            'is_purchasable' => ['boolean'],
            'track_batches' => ['boolean'],
            'track_serials' => ['boolean'],
            'is_bundle' => ['boolean'],
            'units' => ['array', 'max:5'],
            'units.*.unit_id' => ['required', 'integer', 'exists:units,id', 'distinct', 'different:unit_id'],
            'units.*.factor' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'units.*.barcode' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._\-]+$/', 'distinct'],
            'units.*.selling_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'variant_options' => ['array', 'max:3'],
            'variant_options.*.name' => ['required', 'string', 'max:30', 'distinct'],
            'variant_options.*.values' => ['required', 'array', 'min:1', 'max:20'],
            'variant_options.*.values.*' => ['string', 'max:30'],
        ];
    }

    /** Barcode unik di produk & satuan lain; induk varian tidak boleh punya stok; varian bukan paket. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $produk = $this->route('product');
            $id = $produk?->id;
            foreach ((array) $this->input('units', []) as $i => $u) {
                $kode = $u['barcode'] ?? null;
                if (blank($kode)) {
                    continue;
                }
                $dipakai = $kode === $this->input('barcode')
                    || Product::withTrashed()->where('barcode', $kode)->when($id, fn ($q) => $q->whereKeyNot($id))->exists()
                    || ProductUnit::query()->where('barcode', $kode)->when($id, fn ($q) => $q->where('product_id', '!=', $id))->exists();
                if ($dipakai) {
                    $validator->errors()->add("units.{$i}.barcode", "Barcode {$kode} sudah dipakai.");
                }
            }
            if (filled($this->input('barcode')) && ProductUnit::query()->where('barcode', $this->input('barcode'))->when($id, fn ($q) => $q->where('product_id', '!=', $id))->exists()) {
                $validator->errors()->add('barcode', 'Barcode ini sudah dipakai satuan produk lain.');
            }

            $varian = (array) $this->input('variant_options', []);
            if ($varian !== []) {
                $kombinasi = array_product(array_map(fn ($o) => count($o['values']), $varian));
                if ($kombinasi > 100) {
                    $validator->errors()->add('variant_options', "Kombinasi varian paling banyak 100 (sekarang {$kombinasi}).");
                }
                if ($this->boolean('is_bundle')) {
                    $validator->errors()->add('is_bundle', 'Produk bervarian tidak bisa sekaligus paket.');
                }
                if ($produk?->parent_id) {
                    $validator->errors()->add('variant_options', 'Varian tidak bisa punya varian lagi.');
                }
                if ($produk && ! $produk->isVariantParent() && (float) $produk->stock != 0) {
                    $validator->errors()->add('variant_options', 'Produk yang masih punya stok tidak bisa dijadikan induk varian. Kosongkan stoknya dulu.');
                }
            }
            if ($this->boolean('is_bundle') && ($this->boolean('track_batches') || $this->boolean('track_serials'))) {
                $validator->errors()->add('is_bundle', 'Paket tidak melacak batch/seri; komponennya yang melacak.');
            }
        }];
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
