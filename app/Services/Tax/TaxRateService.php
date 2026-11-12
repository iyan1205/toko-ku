<?php

namespace App\Services\Tax;

use App\Models\TaxRate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/** Tarif PPh potong yang berlaku pada suatu tanggal (data dari Pengaturan → Tarif Pajak, bukan kode program). */
class TaxRateService
{
    public function rateFor(string $code, DateTimeInterface|string $date): ?TaxRate
    {
        return TaxRate::query()->where('code', $code)
            ->whereDate('effective_from', '<=', CarbonImmutable::parse($date)->toDateString())
            ->orderByDesc('effective_from')->first();
    }

    /**
     * Pilihan jenis pemotongan: setiap kode dengan tarif yang berlaku pada tanggal itu.
     *
     * @return Collection<string, TaxRate> kode => tarif
     */
    public function options(DateTimeInterface|string|null $date = null): Collection
    {
        $date = CarbonImmutable::parse($date ?? now())->toDateString();

        return TaxRate::query()->whereDate('effective_from', '<=', $date)->orderBy('code')->orderByDesc('effective_from')->get()
            ->unique('code')->keyBy('code');
    }
}
