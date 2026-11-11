<?php

namespace App\Exports\Accounting;

use App\Enums\SettlementType;
use App\Exports\ListExport;
use App\Models\Settlement;
use Illuminate\Database\Eloquent\Builder;

/** Pelunasan piutang (sisi 'piutang') atau hutang (sisi 'hutang'): penerimaan/pembayaran dan nota. */
class SettlementsExport extends ListExport
{
    public function receivable(): bool
    {
        return ($this->filters['side'] ?? 'piutang') === 'piutang';
    }

    public function documentTitle(): string
    {
        return $this->receivable() ? 'Pelunasan Piutang' : 'Pembayaran Hutang';
    }

    protected function baseQuery(): Builder
    {
        $f = $this->filters;
        $party = $this->receivable() ? 'customer' : 'supplier';

        return Settlement::query()->ofTypes(...SettlementType::forSide($this->receivable()))
            ->with(["{$party}:id,name", 'cashBankAccount:id,name'])
            ->withCount('allocations')
            ->when($f['cari'] ?? null, fn (Builder $q, string $kata) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$kata}%")->orWhere('reference', 'like', "%{$kata}%")->orWhere('giro_number', 'like', "%{$kata}%")))
            ->when($f['jenis'] ?? null, fn (Builder $q, string $v) => $q->where('type', $v))
            ->when($f['pihak'] ?? null, fn (Builder $q, $v) => $q->where("{$party}_id", $v))
            ->when($f['status'] ?? null, fn (Builder $q, string $v) => $q->where('status', $v))
            ->when($f['dari'] ?? null, fn (Builder $q, $v) => $q->whereDate('settlement_date', '>=', $v))
            ->when($f['sampai'] ?? null, fn (Builder $q, $v) => $q->whereDate('settlement_date', '<=', $v));
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderByDesc('settlement_date')->orderByDesc('id');
    }

    protected function sorts(): array
    {
        return ['number' => 'number', 'date' => 'settlement_date', 'amount' => 'amount'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Nomor', 'value' => fn (Settlement $s) => $s->number],
            ['label' => 'Tanggal', 'value' => fn (Settlement $s) => $s->settlement_date, 'type' => 'date'],
            ['label' => 'Jenis', 'value' => fn (Settlement $s) => $s->type->label()],
            ['label' => $this->receivable() ? 'Pelanggan' : 'Supplier', 'value' => fn (Settlement $s) => $s->party()?->name],
            ['label' => 'Rekening / Giro', 'value' => fn (Settlement $s) => $s->isGiro() ? "Giro {$s->giro_number}" : $s->cashBankAccount?->name],
            ['label' => 'Faktur', 'value' => fn (Settlement $s) => $s->allocations_count, 'type' => 'qty'],
            ['label' => 'Status', 'value' => fn (Settlement $s) => $s->documentStatus()->label()],
            ['label' => 'Jumlah', 'value' => fn (Settlement $s) => $s->amount, 'type' => 'money'],
            ['label' => 'Uang Muka Baru', 'value' => fn (Settlement $s) => $s->deposit_added, 'type' => 'money'],
        ];
    }
}
