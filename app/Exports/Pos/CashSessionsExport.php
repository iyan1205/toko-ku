<?php

namespace App\Exports\Pos;

use App\Exports\ListExport;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daftar sesi kasir. Filter kasir dipaksa ke pengguna sendiri oleh controller bila
 * pengguna tidak punya izin pos.sessions.approve.
 */
class CashSessionsExport extends ListExport
{
    use DescribesPosFilters;

    public const STATUSES = [
        'open' => 'Terbuka',
        'closed' => 'Ditutup',
        'unverified' => 'Belum diverifikasi',
        'verified' => 'Sudah diverifikasi',
    ];

    public function documentTitle(): string
    {
        return 'Sesi Kasir';
    }

    protected function baseQuery(): Builder
    {
        return CashSession::query()->with(['user:id,name', 'warehouse:id,name'])
            ->withSum(['sales as sales_total' => fn (Builder $q) => $q->where('status', 'completed')], 'grand_total')
            ->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('opened_at')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return [
            'number' => 'number',
            'opened' => 'opened_at',
            'difference' => 'cash_difference',
            'cashier' => fn (Builder $q, string $dir) => $q->orderBy(User::withTrashed()->select('name')->whereColumn('users.id', 'cash_sessions.user_id'), $dir),
        ];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (CashSession $s) => $s->number],
            ['label' => 'Kasir', 'value' => fn (CashSession $s) => $s->user?->name],
            ['label' => 'Gudang', 'value' => fn (CashSession $s) => $s->warehouse?->name],
            ['label' => 'Dibuka', 'value' => fn (CashSession $s) => $s->opened_at, 'type' => 'datetime'],
            ['label' => 'Ditutup', 'value' => fn (CashSession $s) => $s->closed_at, 'type' => 'datetime'],
            ['label' => 'Modal Awal', 'value' => fn (CashSession $s) => $s->opening_cash, 'type' => 'money'],
            ['label' => 'Penjualan', 'value' => fn (CashSession $s) => $s->sales_total ?? 0, 'type' => 'money'],
            ['label' => 'Tunai Seharusnya', 'value' => fn (CashSession $s) => $s->expected_cash, 'type' => 'money'],
            ['label' => 'Uang Fisik', 'value' => fn (CashSession $s) => $s->closing_cash, 'type' => 'money'],
            ['label' => 'Selisih', 'value' => fn (CashSession $s) => $s->cash_difference, 'type' => 'money'],
            ['label' => 'Status', 'value' => fn (CashSession $s) => $s->isOpen() ? 'Terbuka' : ($s->verified_at ? 'Diverifikasi' : 'Ditutup')],
        ];
    }

    protected function filterSummary(): array
    {
        return $this->commonSummary(self::STATUSES);
    }
}
