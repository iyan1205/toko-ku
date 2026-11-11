<?php

namespace App\Exports\Accounting;

use App\Enums\CashTransactionType;
use App\Enums\DocumentStatus;
use App\Exports\ListExport;
use App\Models\CashBankAccount;
use App\Models\CashTransaction;
use Illuminate\Database\Eloquent\Builder;

/** Transaksi kas & bank: penerimaan, pengeluaran, transfer. */
class CashTransactionsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Transaksi Kas & Bank';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;

        return CashTransaction::query()
            ->with(['cashBankAccount:id,name', 'targetCashBankAccount:id,name', 'creator:id,name'])
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$kata}%")->orWhere('description', 'like', "%{$kata}%")->orWhere('counterparty', 'like', "%{$kata}%")))
            ->when($f['jenis'] ?? null, fn (Builder $q, string $v) => $q->where('type', $v))
            ->when($f['rekening'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $w) => $w->where('cash_bank_account_id', $v)->orWhere('target_cash_bank_account_id', $v)))
            ->when($f['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('transaction_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('transaction_date', '<=', $v));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('transaction_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return ['number' => 'number', 'date' => 'transaction_date', 'amount' => 'amount'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (CashTransaction $t) => $t->number],
            ['label' => 'Tanggal', 'value' => fn (CashTransaction $t) => $t->transaction_date, 'type' => 'date'],
            ['label' => 'Jenis', 'value' => fn (CashTransaction $t) => $t->type->label()],
            ['label' => 'Rekening', 'value' => fn (CashTransaction $t) => $t->cashBankAccount?->name.($t->targetCashBankAccount ? ' → '.$t->targetCashBankAccount->name : '')],
            ['label' => 'Keterangan', 'value' => fn (CashTransaction $t) => $t->description],
            ['label' => 'Pihak', 'value' => fn (CashTransaction $t) => $t->counterparty],
            ['label' => 'Status', 'value' => fn (CashTransaction $t) => $t->documentStatus()->label()],
            ['label' => 'Jumlah', 'value' => fn (CashTransaction $t) => $t->amount, 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            'Jenis' => isset($f['jenis']) ? CashTransactionType::tryFrom($f['jenis'])?->label() : null,
            'Rekening' => isset($f['rekening']) ? CashBankAccount::withTrashed()->find($f['rekening'])?->name : null,
            'Status' => isset($f['status']) ? DocumentStatus::tryFrom($f['status'])?->label() : null,
            'Periode' => isset($f['dari']) || isset($f['sampai']) ? trim(($f['dari'] ?? '…').' s.d. '.($f['sampai'] ?? '…')) : null,
        ]);
    }
}
