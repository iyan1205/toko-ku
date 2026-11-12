{{-- Daftar anggaran per tahun. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Anggaran</x-slot>
    <x-slot name="description">Rencana pendapatan dan beban per akun per bulan, untuk semua cabang atau satu cabang dan cost center. Realisasinya dari jurnal yang sudah diposting.</x-slot>
    <x-slot name="actions">
        @can('accounting.budgets.create')
            <x-ui.button icon="plus" :href="route('budgets.create')">Anggaran baru</x-ui.button>
        @endcan
    </x-slot>

    <div class="mb-4 flex items-center gap-2">
        <x-ui.button variant="ghost" size="sm" icon="chevron-left" :href="route('budgets.index', ['tahun' => $year - 1])" aria-label="Tahun sebelumnya" />
        <span class="text-sm font-semibold text-slate-900">{{ $year }}</span>
        <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('budgets.index', ['tahun' => $year + 1])" aria-label="Tahun berikutnya" />
    </div>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Anggaran</x-ui.table.th>
            <x-ui.table.th>Cakupan</x-ui.table.th>
            <x-ui.table.th align="right">Total setahun</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @forelse ($budgets as $b)
            <tr>
                <x-ui.table.td><a href="{{ route('budgets.show', $b) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $b->name }}</a></x-ui.table.td>
                <x-ui.table.td muted>{{ $b->coverageLabel() }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($b->lines_sum_amount ?? 0)</x-ui.table.td>
                <x-ui.table.td align="right">
                    @can('accounting.budgets.create')
                        <x-ui.button variant="ghost" size="sm" icon="document-duplicate" :href="route('budgets.create', ['salin' => $b->id])">Salin ke {{ $b->fiscal_year + 1 }}</x-ui.button>
                    @endcan
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="4" icon="chart-pie" :title="'Belum ada anggaran '.$year" description="Buat anggaran untuk membandingkan rencana dengan realisasi setiap bulan." />
        @endforelse
    </x-ui.table>
</x-app-layout>
