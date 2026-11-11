{{-- Saringan bersama Buku Besar dan Neraca Saldo: periode, cabang (bila lebih dari satu), cost center. --}}
<x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="$filters['dari']" />
<x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="$filters['sampai']" />
@if ($branches->isNotEmpty())
    <x-ui.select name="cabang" label="Cabang" hide-label :options="$branches->pluck('name', 'id')" :selected="$filters['cabang'] ?? null" placeholder="Semua cabang" />
@endif
@if ($costCenters->isNotEmpty())
    <x-ui.select name="cost_center" label="Cost center" hide-label
                 :options="['tanpa' => 'Tanpa cost center'] + $costCenters->mapWithKeys(fn ($c) => [$c->id => $c->code.' '.$c->name.($c->is_active ? '' : ' (nonaktif)')])->all()"
                 :selected="$filters['cost_center'] ?? null" placeholder="Semua cost center" />
@endif
