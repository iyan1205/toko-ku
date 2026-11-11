<?php

namespace App\Http\Requests\Accounting;

use App\Enums\CashBankKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting.cash-bank.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->has('is_active') ? $this->boolean('is_active') : true]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(CashBankKind::class)],
            'name' => ['required', 'string', 'max:100'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50'],
            'account_holder' => ['nullable', 'string', 'max:100'],
            'imprest_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2', Rule::requiredIf($this->input('kind') === CashBankKind::PettyCash->value)],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'account_id' => [$this->route('cashBankAccount') ? 'prohibited' : 'nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['kind' => 'jenis', 'name' => 'nama rekening', 'imprest_amount' => 'plafon kas kecil', 'account_id' => 'akun', 'branch_id' => 'cabang'];
    }
}
