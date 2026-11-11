<?php

namespace App\Http\Requests\Accounting;

use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bentuk data transaksi kas. Aturan rekening, akun, dan jumlah diperiksa CashTransactionService. */
class CashTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting.cash-bank.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uang = ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'];

        return [
            'aksi' => ['nullable', Rule::in(['draft', 'post'])],
            'transaction_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'cash_bank_account_id' => ['required', 'integer'],
            'target_cash_bank_account_id' => ['nullable', 'integer'],
            'counterparty' => ['nullable', 'string', 'max:150'],
            'reference' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => $uang,
            'fee' => $uang,
            'fee_account_id' => ['nullable', 'integer'],
            'lines' => ['nullable', 'array', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.cost_center_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => $uang,
        ];
    }

    public function attributes(): array
    {
        return [
            'transaction_date' => 'tanggal',
            'cash_bank_account_id' => 'rekening',
            'target_cash_bank_account_id' => 'rekening tujuan',
            'counterparty' => 'pihak',
            'description' => 'keterangan',
            'amount' => 'jumlah',
            'fee' => 'biaya',
            'lines.*.amount' => 'jumlah',
        ];
    }

    public function messages(): array
    {
        return ['transaction_date.before_or_equal' => 'Tanggal tidak boleh lebih dari hari ini.'];
    }
}
