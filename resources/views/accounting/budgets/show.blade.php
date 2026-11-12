{{-- Anggaran vs realisasi per akun untuk rentang bulan. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $bulan = collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => \Carbon\CarbonImmutable::create(2000, $m, 1)->translatedFormat('F')]);
    $peringatan = collect($report['rows'])->where('warning', true)->count();
@endphp

<x-app-layout>
    <x-slot name="header">{{ $budget->name }}</x-slot>
    <x-slot name="description">{{ $budget->fiscal_year }} · {{ $budget->coverageLabel() }} · realisasi {{ Format::tanggal($report['from']) }} s.d. {{ Format::tanggal($report['to']) }}</x-slot>
    <x-slot name="actions">
        @can('accounting.budgets.delete')
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600" x-data
                x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('budgets.destroy', $budget)) }}, nama: {{ \Illuminate\Support\Js::from('anggaran '.$budget->name) }} })">Hapus</x-ui.button>
        @endcan
        @can('accounting.budgets.update')
            <x-ui.button variant="secondary" icon="pencil-square" :href="route('budgets.edit', $budget)">Ubah anggaran</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <x-ui.select name="dari" label="Dari bulan" :options="$bulan" :selected="$fromMonth" />
            <x-ui.select name="sampai" label="Sampai bulan" :options="$bulan" :selected="$toMonth" />
            <x-ui.button type="submit" variant="secondary" icon="funnel">Terapkan</x-ui.button>
        </form>
    </x-ui.card>

    @if ($peringatan > 0)
        <x-ui.alert type="warning" class="mb-6" title="{{ $peringatan }} akun di luar anggaran">Beban/HPP yang melebihi anggaran atau pendapatan yang di bawah target ditandai merah.</x-ui.alert>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        @foreach ($report['groups'] as $kelompok => $g)
            <x-ui.stat-card :label="$kelompok" :value="$rp($g['actual'])" icon="chart-pie" color="neutral" :hint="'Anggaran '.$rp($g['budget']).($g['budget'] > 0 ? ' · '.round($g['actual'] / $g['budget'] * 100, 1).'%' : '')" />
        @endforeach
    </div>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Akun</x-ui.table.th>
            <x-ui.table.th align="right">Anggaran</x-ui.table.th>
            <x-ui.table.th align="right">Realisasi</x-ui.table.th>
            <x-ui.table.th align="right">Selisih</x-ui.table.th>
            <x-ui.table.th align="right">%</x-ui.table.th>
        </x-slot:head>
        @foreach ($report['rows'] as $row)
            <tr>
                <x-ui.table.td>
                    @can('accounting.ledger.view')
                        <a href="{{ route('ledger.index', array_filter(['akun' => $row['account']->id, 'dari' => $report['from'], 'sampai' => $report['to'], 'cabang' => $budget->branch_id, 'cost_center' => $budget->cost_center_id])) }}" class="hover:text-primary-700 hover:underline">
                            <span class="font-mono text-xs text-slate-500">{{ $row['account']->code }}</span> {{ $row['account']->name }}
                        </a>
                    @else
                        <span class="font-mono text-xs text-slate-500">{{ $row['account']->code }}</span> {{ $row['account']->name }}
                    @endcan
                    <p class="text-xs text-slate-500">{{ $row['account']->type->label() }}</p>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $rp($row['budget']) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $rp($row['actual']) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="$row['warning'] ? 'font-medium text-danger-700' : ''">{{ $rp($row['variance']) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @if ($row['percent'] !== null)
                        <x-ui.badge :color="$row['warning'] ? 'danger' : 'success'">{{ $row['percent'] }}%</x-ui.badge>
                    @else
                        —
                    @endif
                </x-ui.table.td>
            </tr>
        @endforeach
    </x-ui.table>
</x-app-layout>
