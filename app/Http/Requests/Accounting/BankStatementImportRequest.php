<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class BankStatementImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting.reconciliation.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start', 'before_or_equal:today'],
            'opening_balance' => ['nullable', 'numeric', 'decimal:0,2'],
            'closing_balance' => ['required', 'numeric', 'decimal:0,2'],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('imports.mimes')), 'max:'.config('imports.max_kb')],
        ];
    }

    public function attributes(): array
    {
        return [
            'cash_bank_account_id' => 'rekening',
            'period_start' => 'awal periode',
            'period_end' => 'akhir periode',
            'opening_balance' => 'saldo awal rekening koran',
            'closing_balance' => 'saldo akhir rekening koran',
            'file' => 'berkas rekening koran',
        ];
    }
}
