<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/** Aset baru: semua isian. Ubah aset: hanya keterangan (nama, lokasi, penanggung jawab, cost center, nomor seri). */
class FixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('fixedAsset') ? 'accounting.assets.update' : 'accounting.assets.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $keterangan = [
            'name' => ['required', 'string', 'max:150'],
            'location' => ['nullable', 'string', 'max:150'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'holder_name' => ['nullable', 'string', 'max:100'],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ];

        if ($this->route('fixedAsset')) {
            return $keterangan;
        }

        $uang = ['numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];

        return $keterangan + [
            'asset_category_id' => ['required', 'integer'],
            'acquisition_date' => ['required', 'date', 'before_or_equal:today'],
            'acquisition_cost' => ['required', ...$uang],
            'residual_value' => ['nullable', ...$uang],
            'useful_life_months' => ['nullable', 'integer', 'between:1,600'],
            'depreciation_start' => ['nullable', 'date'],
            'opening_accumulated' => ['nullable', ...$uang],
            'credit_account_id' => ['required', 'integer'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama aset', 'asset_category_id' => 'kategori', 'acquisition_date' => 'tanggal perolehan', 'acquisition_cost' => 'harga perolehan',
            'residual_value' => 'nilai sisa', 'useful_life_months' => 'umur ekonomis', 'depreciation_start' => 'mulai disusutkan',
            'opening_accumulated' => 'akumulasi awal', 'credit_account_id' => 'sumber dana', 'employee_id' => 'penanggung jawab',
        ];
    }
}
