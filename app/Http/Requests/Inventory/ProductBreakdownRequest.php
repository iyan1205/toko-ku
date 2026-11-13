<?php

namespace App\Http\Requests\Inventory;

use App\Models\ProductBreakdown;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductBreakdownRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('breakdown') ? 'inventory.recipes.update' : 'inventory.recipes.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'outputs' => array_values(array_filter((array) $this->input('outputs', []), fn ($o) => is_array($o) && filled($o['product_id'] ?? null))),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at'),
                Rule::unique('product_breakdowns', 'source_product_id')->ignore($this->route('breakdown')?->id)],
            'source_quantity' => ['required', 'numeric', 'min:0.001', 'max:99999'],
            'cost_method' => ['required', Rule::in(array_keys(ProductBreakdown::COST_METHODS))],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'outputs' => ['required', 'array', 'min:1', 'max:20'],
            'outputs.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at'), 'distinct', 'different:source_product_id'],
            'outputs.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:99999'],
        ];
    }

    /** Hasil tidak boleh sama dengan bahan, dan tiap hasil hanya berasal dari satu pemecahan aktif. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ((array) $this->input('outputs') as $i => $o) {
                if ((int) $o['product_id'] === (int) $this->input('source_product_id')) {
                    $validator->errors()->add("outputs.{$i}.product_id", 'Hasil tidak boleh sama dengan bahannya.');
                }
                $lain = ProductBreakdown::query()->where('is_active', true)->when($this->route('breakdown'), fn ($q, $b) => $q->whereKeyNot($b->id))
                    ->whereHas('outputs', fn ($q) => $q->where('product_id', (int) $o['product_id']))->with('source:id,name')->first();
                if ($lain !== null) {
                    $validator->errors()->add("outputs.{$i}.product_id", "Produk ini sudah menjadi hasil pemecahan {$lain->source?->name}.");
                }
            }
        }];
    }

    public function attributes(): array
    {
        return [
            'source_product_id' => 'bahan', 'source_quantity' => 'jumlah bahan', 'cost_method' => 'pembagian biaya',
            'outputs' => 'hasil', 'outputs.*.product_id' => 'produk hasil', 'outputs.*.quantity' => 'jumlah hasil',
        ];
    }

    public function messages(): array
    {
        return ['source_product_id.unique' => 'Bahan ini sudah punya pemecahan. Ubah yang ada.', 'outputs.*.product_id.distinct' => 'Produk hasil yang sama muncul dua kali.'];
    }
}
