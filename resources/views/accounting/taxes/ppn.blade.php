{{-- Rekap PPN per masa: keluaran, masukan, kurang/lebih bayar (dari jurnal posted). --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $total = ['output' => array_sum(array_column($rows, 'output')), 'input' => array_sum(array_column($rows, 'input'))];
@endphp

<x-app-layout>
    <x-slot name="header">Pajak</x-slot>
    <x-slot name="description">Rekap PPN dari jurnal yang sudah diposting: retur, pembatalan, dan koreksi ikut terhitung. Cocokkan dengan SPT Masa PPN sebelum lapor.</x-slot>

    @include('accounting.taxes.nav')

    <x-ui.card class="mb-6">
        <x-ui.filter-bar :search="false">
            <x-ui.input name="tahun" label="Tahun" hide-label type="number" min="2000" max="2100" :value="$year" />
            @if ($branches->isNotEmpty())
                <x-ui.select name="cabang" label="Cabang" hide-label :options="$branches->pluck('name', 'id')" :selected="request('cabang')" placeholder="Semua cabang" />
            @endif
        </x-ui.filter-bar>
    </x-ui.card>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Masa</x-ui.table.th>
            <x-ui.table.th align="right">PPN keluaran</x-ui.table.th>
            <x-ui.table.th align="right">PPN masukan</x-ui.table.th>
            <x-ui.table.th align="right">Kurang (lebih) bayar</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @foreach ($rows as $row)
            <tr>
                <x-ui.table.td>{{ Format::bulan($row['period'].'-01') }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $rp($row['output']) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $rp($row['input']) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="$row['net'] > 0 ? 'font-medium text-danger-700' : ($row['net'] < 0 ? 'font-medium text-success-700' : '')">
                    {{ $row['net'] < 0 ? '('.$rp(-$row['net']).')' : $rp($row['net']) }}
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    @if ($row['output'] !== 0 || $row['input'] !== 0)
                        <x-ui.button variant="ghost" size="sm" :href="route('taxes.documents', ['masa' => $row['period']])">Faktur</x-ui.button>
                    @endif
                </x-ui.table.td>
            </tr>
        @endforeach
        <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
            <x-ui.table.td class="text-slate-900">Setahun</x-ui.table.td>
            <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($total['output']) }}</x-ui.table.td>
            <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($total['input']) }}</x-ui.table.td>
            <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($total['output'] - $total['input']) }}</x-ui.table.td>
            <x-ui.table.td></x-ui.table.td>
        </tr>
    </x-ui.table>
</x-app-layout>
