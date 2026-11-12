<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/** Anggaran: lines[akun][bulan] = nilai. Akun dan nilai diperiksa BudgetService. */
class BudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('budget') ? 'accounting.budgets.update' : 'accounting.budgets.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'cost_center_id' => ['nullable', 'integer', 'exists:cost_centers,id'],
            'notes' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'max:300'],
            'lines.*' => ['array'],
            'lines.*.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama anggaran', 'fiscal_year' => 'tahun', 'branch_id' => 'cabang', 'cost_center_id' => 'cost center', 'lines' => 'nilai anggaran'];
    }
}
