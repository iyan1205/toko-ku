{{-- Satu laporan keuangan: saringan, tabel bertingkat, angka akun menuju buku besar. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (?int $sen) => $sen === null ? '' : ($sen < 0 ? '('.Format::rupiah(Uang::keDesimal(-$sen)).')' : Format::rupiah(Uang::keDesimal($sen)));
    $bolehBukuBesar = auth()->user()->can('accounting.ledger.view');
    $modeOptions = $kind === 'income'
        ? ['single' => 'Satu periode', 'monthly' => 'Per bulan', 'previous' => 'Banding periode sebelumnya', 'last_year' => 'Banding tahun lalu']
        : ['single' => 'Tanpa pembanding', 'tahun_lalu' => 'Banding akhir tahun lalu'];
@endphp

<x-app-layout>
    <x-slot name="header">{{ $report['title'] }}</x-slot>
    <x-slot name="description">{{ $report['subtitle'] }}</x-slot>
    <x-slot name="actions">
        @can('accounting.reports.export')
            <x-ui.export-menu :href="url()->current()" />
        @endcan
    </x-slot>

    @include('accounting.reports.nav')

    <x-ui.card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            @if ($kind === 'balance')
                <x-ui.input type="date" name="per" label="Per tanggal" :value="$filters['per']" />
            @else
                <x-ui.input type="date" name="dari" label="Dari" :value="$filters['dari']" />
                <x-ui.input type="date" name="sampai" label="Sampai" :value="$filters['sampai']" />
            @endif
            @if (in_array($kind, ['income', 'balance'], true))
                <x-ui.select name="banding" label="Tampilan" :options="$modeOptions" :selected="$filters['banding']" />
            @endif
            @if ($branches->count() > 1)
                <x-ui.select name="cabang" label="Cabang" :options="$branches->pluck('name', 'id')" :selected="$filters['cabang'] ?? null" placeholder="Semua cabang" />
            @endif
            @if ($kind === 'income' && $costCenters->isNotEmpty())
                <x-ui.select name="cost_center" label="Cost center" :options="$costCenters->mapWithKeys(fn ($c) => [$c->id => $c->label()])" :selected="$filters['cost_center'] ?? null" placeholder="Semua cost center" />
            @endif
            <x-ui.button type="submit" variant="secondary" icon="funnel">Terapkan</x-ui.button>
        </form>
    </x-ui.card>

    @isset($report['check'])
        <x-ui.alert :type="$report['check']['ok'] ? 'success' : 'danger'" class="mb-6">{{ $report['check']['message'] }}</x-ui.alert>
    @endisset

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Keterangan</x-ui.table.th>
            @foreach ($report['columns'] as $kolom)
                <x-ui.table.th align="right">{{ $kolom }}</x-ui.table.th>
            @endforeach
        </x-slot:head>
        @foreach ($report['rows'] as $row)
            @if ($row['type'] === 'section')
                <tr class="bg-slate-50">
                    <td colspan="{{ count($report['columns']) + 1 }}" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $row['label'] }}</td>
                </tr>
            @else
                <tr @class(['font-semibold' => $row['type'] !== 'account', 'bg-slate-50/60' => $row['type'] === 'grand'])>
                    <x-ui.table.td :class="$row['type'] === 'account' ? 'pl-8' : ''">{{ $row['label'] }}</x-ui.table.td>
                    @foreach ($row['values'] as $i => $nilai)
                        <x-ui.table.td align="right" numeric :class="($nilai ?? 0) < 0 ? 'text-danger-700' : ''">
                            @if ($bolehBukuBesar && ! empty($row['links'][$i]) && $nilai !== 0)
                                <a href="{{ $row['links'][$i] }}" class="hover:text-primary-700 hover:underline">{{ $rp($nilai) }}</a>
                            @else
                                {{ $rp($nilai) }}
                            @endif
                        </x-ui.table.td>
                    @endforeach
                </tr>
            @endif
        @endforeach
    </x-ui.table>

    @isset($report['note'])
        <p class="mt-4 text-sm text-slate-500">{{ $report['note'] }}</p>
    @endisset
</x-app-layout>
