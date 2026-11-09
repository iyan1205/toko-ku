<?php

namespace App\Http\Requests;

use App\Models\Recipe;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $recipe = $this->route('resep') ?? $this->route('recipe');

        return $this->user()->can($recipe ? 'inventory.recipes.update' : 'inventory.recipes.create');
    }

    public function rules(): array
    {
        $productId = $this->input('product_id');
        // Parameter route resource 'recipe' bernama {recipe}.
        $recipe = $this->route('recipe');
        $recipeId = $recipe?->id;

        return [
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => [
                'required', 'integer', Rule::exists('products', 'id'),
                // Bahan baku tidak boleh sama dengan produk jualnya sendiri.
                Rule::notIn([$productId]),
                // Satu bahan hanya boleh muncul sekali per resep (dicek di seluruh items yang dikirim).
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $items = (array) $this->input('items', []);
                    $productIds = array_map(fn ($it) => (int) ($it['product_id'] ?? 0), $items);
                    $duplicates = array_filter(array_count_values($productIds), fn ($c) => $c > 1);
                    if (isset($duplicates[(int) $value])) {
                        $fail('Bahan baku sudah ada di resep ini. Gabungkan jumlahnya.');
                    }
                },
            ],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.product_id.not_in' => 'Bahan baku tidak boleh sama dengan produk jualnya sendiri.',
            'items.*.product_id.unique' => 'Bahan baku sudah ada di resep ini. Gabungkan jumlahnya.',
            'items.*.quantity.min' => 'Jumlah harus lebih dari nol.',
        ];
    }
}
