{{-- Daftar dokumen ber-PPN satu masa (keluaran/masukan) dan ekspornya. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $tanpaNpwp = $direction === 'masukan' ? $documents->filter(fn ($d) => blank($d['party_tax_id']))->count() : 0;
@endphp

<x-app-layout>
    <x-slot name="header">Faktur PPN {{ $direction }}</x-slot>
    <x-slot name="description">Dokumen ber-PPN di masa {{ Format::bulan($period.'-01') }}. Susunan kolom ekspor diatur di config/tax-export.php agar bisa menyesuaikan format sistem pajak.</x-slot>
    <x-slot name="actions">
        @can('accounting.taxes.export')
            <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('taxes.export', ['masa' => $period, 'arah' => $direction, 'format' => 'xlsx'])">Excel</x-ui.button>
            <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('taxes.export', ['masa' => $period, 'arah' => $direction, 'format' => 'csv'])">CSV</x-ui.button>
        @endcan
    </x-slot>

    @include('accounting.taxes.nav')

    <x-ui.card class="mb-6">
        <x-ui.filter-bar :search="false">
            <x-ui.input name="masa" label="Masa" hide-label type="month" :value="$period" />
            <x-ui.select name="arah" label="Arah" hide-label :options="['keluaran' => 'PPN keluaran (penjualan)', 'masukan' => 'PPN masukan (pembelian)']" :selected="$direction" />
        </x-ui.filter-bar>
    </x-ui.card>

    @if ($tanpaNpwp > 0)
        <x-ui.alert type="warning" class="mb-6">{{ $tanpaNpwp }} dokumen dari supplier tanpa NPWP. Lengkapi NPWP di data supplier.</x-ui.alert>
    @endif

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Dokumen</x-ui.table.th>
            <x-ui.table.th>{{ $direction === 'keluaran' ? 'Pembeli' : 'Penjual' }}</x-ui.table.th>
            <x-ui.table.th align="right">DPP</x-ui.table.th>
            <x-ui.table.th align="right">PPN</x-ui.table.th>
        </x-slot:head>
        @forelse ($documents as $d)
            <tr>
                <x-ui.table.td numeric muted>{{ Format::tanggal($d['date']) }}</x-ui.table.td>
                <x-ui.table.td>
                    @if ($d['url'])
                        <a href="{{ $d['url'] }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $d['number'] }}</a>
                    @else
                        {{ $d['number'] }}
                    @endif
                    <p class="text-xs text-slate-500">{{ $d['type'] }}{{ $d['reference'] ? ' · '.$d['reference'] : '' }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    {{ $d['party_name'] }}
                    <p class="text-xs text-slate-500">{{ $d['party_tax_id'] ?? 'Tanpa NPWP/NIK' }}</p>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $rp($d['dpp']) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $rp($d['ppn']) }} <span class="text-xs text-slate-500">({{ $d['rate'] }}%)</span></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="receipt-percent" title="Tidak ada dokumen ber-PPN di masa ini" />
        @endforelse
        @if ($documents->isNotEmpty())
            <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                <x-ui.table.td colspan="3" class="text-slate-900">Total ({{ $documents->count() }} dokumen)</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($documents->sum('dpp')) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($documents->sum('ppn')) }}</x-ui.table.td>
            </tr>
        @endif
    </x-ui.table>
</x-app-layout>
