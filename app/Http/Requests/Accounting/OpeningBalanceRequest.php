<?php

namespace App\Http\Requests\Accounting;

use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Bentuk data saldo awal. Aturan per baris (akun, gudang cabang, pihak) diperiksa OpeningBalanceService. */
class OpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->input('aksi') === 'post' && ! $this->user()->can('accounting.opening-balances.approve')) {
            return false;
        }

        return $this->user()->can('accounting.opening-balances.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uang = ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'];

        return [
            'aksi' => ['nullable', Rule::in(['draft', 'post'])],
            'balance_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'description' => ['nullable', 'string', 'max:255'],
            'accounts' => ['nullable', 'array', 'max:200'],
            'accounts.*.account_id' => ['nullable', 'integer'],
            'accounts.*.description' => ['nullable', 'string', 'max:255'],
            'accounts.*.debit' => $uang,
            'accounts.*.credit' => $uang,
            'inventory' => ['nullable', 'array', 'max:100'],
            'inventory.*.warehouse_id' => ['nullable', 'integer'],
            'inventory.*.amount' => $uang,
            'receivables' => ['nullable', 'array', 'max:500'],
            'receivables.*.customer_id' => ['nullable', 'integer'],
            'receivables.*.reference' => ['nullable', 'string', 'max:50'],
            'receivables.*.due_date' => ['nullable', 'date'],
            'receivables.*.amount' => $uang,
            'payables' => ['nullable', 'array', 'max:500'],
            'payables.*.supplier_id' => ['nullable', 'integer'],
            'payables.*.reference' => ['nullable', 'string', 'max:50'],
            'payables.*.due_date' => ['nullable', 'date'],
            'payables.*.amount' => $uang,
        ];
    }

    public function attributes(): array
    {
        return [
            'balance_date' => 'tanggal saldo awal',
            'description' => 'keterangan',
            'accounts.*.debit' => 'debit',
            'accounts.*.credit' => 'kredit',
            'inventory.*.amount' => 'nilai persediaan',
            'receivables.*.amount' => 'nilai piutang',
            'receivables.*.due_date' => 'jatuh tempo',
            'payables.*.amount' => 'nilai hutang',
            'payables.*.due_date' => 'jatuh tempo',
        ];
    }

    public function messages(): array
    {
        return ['balance_date.before_or_equal' => 'Tanggal saldo awal tidak boleh lebih dari hari ini.'];
    }
}
