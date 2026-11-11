<?php

namespace App\Http\Requests\Accounting;

use App\Enums\SettlementType;
use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Pelunasan piutang/hutang. Sisi dari nama route; jumlah dan alokasi diperiksa SettlementService. */
class SettlementRequest extends FormRequest
{
    public function receivable(): bool
    {
        return Str::startsWith((string) $this->route()?->getName(), 'receivable-settlements.');
    }

    public function authorize(): bool
    {
        return $this->user()->can($this->receivable() ? 'pos.receivables.create' : 'purchasing.payables.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uang = ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'];
        $types = array_map(fn (SettlementType $t) => $t->value, SettlementType::forSide($this->receivable()));

        return [
            'type' => ['required', Rule::in($types)],
            'customer_id' => [$this->receivable() ? 'required' : 'prohibited', 'nullable', 'integer'],
            'supplier_id' => [$this->receivable() ? 'prohibited' : 'required', 'nullable', 'integer'],
            'settlement_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'method' => ['nullable', Rule::in(['cash', 'transfer', 'giro'])],
            'cash_bank_account_id' => ['nullable', 'integer'],
            'amount' => $uang,
            'deposit_applied' => $uang,
            'counter_account_id' => ['nullable', 'integer'],
            'giro_number' => ['nullable', 'string', 'max:50'],
            'giro_bank' => ['nullable', 'string', 'max:100'],
            'giro_due_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
            'allocations' => ['nullable', 'array', 'max:200'],
            'allocations.*' => $uang,
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'pelanggan',
            'supplier_id' => 'supplier',
            'settlement_date' => 'tanggal',
            'cash_bank_account_id' => 'rekening',
            'amount' => 'jumlah',
            'deposit_applied' => 'uang muka dipakai',
            'counter_account_id' => 'akun nota',
            'giro_number' => 'nomor giro',
            'giro_due_date' => 'jatuh tempo giro',
            'allocations.*' => 'nilai alokasi',
        ];
    }
}
