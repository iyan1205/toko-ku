<?php

namespace App\Exports\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Exports\ListExport;
use App\Models\Account;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;

/** Bagan akun beserta saldonya (dari semua baris jurnal). */
class AccountsExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Bagan Akun';
    }

    /** Saldo sesuai sisi normal akun: debit - kredit untuk akun bersaldo normal debit. */
    public static function balance(Account $account): float
    {
        $debit = Uang::sen($account->getAttribute('debit_total') ?? 0);
        $credit = Uang::sen($account->getAttribute('credit_total') ?? 0);
        $saldo = $account->normal_balance === NormalBalance::Debit ? $debit - $credit : $credit - $debit;

        return (float) Uang::keDesimal($saldo);
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;

        return Account::query()
            ->with('parent:id,code,name,parent_id')
            ->withSum('lines as debit_total', 'debit')
            ->withSum('lines as credit_total', 'credit')
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('code', 'like', "%{$kata}%")->orWhere('name', 'like', "%{$kata}%")))
            ->when($f['tipe'] ?? null, fn (Builder $q, string $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn (Builder $q, string $v) => $q->where('is_active', $v === 'aktif'));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('code');
    }

    protected function sorts(): array
    {
        return ['code' => 'code', 'name' => 'name'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Kode', 'value' => fn (Account $a) => $a->code],
            ['label' => 'Nama', 'value' => fn (Account $a) => $a->name],
            ['label' => 'Kelompok', 'value' => fn (Account $a) => $a->type->label()],
            ['label' => 'Saldo Normal', 'value' => fn (Account $a) => $a->normal_balance->label()],
            ['label' => 'Induk', 'value' => fn (Account $a) => $a->parent?->label()],
            ['label' => 'Jenis', 'value' => fn (Account $a) => $a->is_header ? 'Akun induk' : 'Akun transaksi'],
            ['label' => 'Status', 'value' => fn (Account $a) => $a->is_active ? 'Aktif' : 'Nonaktif'],
            ['label' => 'Saldo', 'value' => fn (Account $a) => $a->is_header ? null : self::balance($a), 'type' => 'money'],
        ];
    }

    protected function filterSummary(): array
    {
        return array_filter([
            'Pencarian' => $this->filters['cari'] ?? null,
            'Kelompok' => isset($this->filters['tipe']) ? AccountType::tryFrom($this->filters['tipe'])?->label() : null,
            'Status' => isset($this->filters['status']) ? ucfirst($this->filters['status']) : null,
        ]);
    }
}
