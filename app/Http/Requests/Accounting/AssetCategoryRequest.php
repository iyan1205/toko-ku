<?php

namespace App\Http\Requests\Accounting;

use App\Enums\DepreciationMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('assetCategory') ? 'accounting.assets.update' : 'accounting.assets.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
            'is_active' => $this->has('is_active') ? $this->boolean('is_active') : true,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $akun = ['required', 'integer', Rule::exists('accounts', 'id')->where('is_header', false)->where('is_active', true)->whereNull('deleted_at')];

        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Z.\-_]+$/', Rule::unique('asset_categories', 'code')->ignore($this->route('assetCategory')?->id)],
            'name' => ['required', 'string', 'max:100'],
            'useful_life_months' => ['required', 'integer', 'between:1,600'],
            'method' => ['required', Rule::enum(DepreciationMethod::class)],
            'declining_rate' => ['nullable', 'numeric', 'between:0.01,100', Rule::requiredIf($this->input('method') === DepreciationMethod::DecliningBalance->value)],
            'asset_account_id' => $akun,
            'accumulated_account_id' => [...$akun, 'different:asset_account_id'],
            'expense_account_id' => $akun,
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode', 'name' => 'nama', 'useful_life_months' => 'umur ekonomis', 'method' => 'metode', 'declining_rate' => 'tarif saldo menurun',
            'asset_account_id' => 'akun aset', 'accumulated_account_id' => 'akun akumulasi penyusutan', 'expense_account_id' => 'akun beban penyusutan',
        ];
    }
}
