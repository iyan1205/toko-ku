{{-- Penyusutan: pratinjau periode, hitung & jurnal, dan riwayat run. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $total = $preview->sum('total');
@endphp

<x-app-layout>
    <x-slot name="header">Penyusutan Aset</x-slot>
    <x-slot name="description">Dijurnal otomatis tanggal 1 setiap bulan untuk bulan sebelumnya. Bulan yang terlewat ikut disusutkan susulan.</x-slot>

    @include('accounting.assets.nav')

    @unless ($schedulerAlive)
        <x-ui.alert type="warning" class="mb-6">Scheduler belum berjalan, jadi penyusutan tidak dijurnal otomatis. Hitung manual di sini setiap awal bulan.</x-ui.alert>
    @endunless

    <x-ui.card class="mb-6">
        <form method="GET" action="{{ route('depreciation.index') }}" class="flex flex-wrap items-end gap-3">
            <x-ui.input name="periode" label="Periode" type="month" :value="$period" :max="now()->format('Y-m')" />
            <x-ui.button type="submit" variant="secondary" icon="eye">Pratinjau</x-ui.button>
        </form>
    </x-ui.card>

    <x-ui.table :sticky="false" class="mb-6">
        <x-slot:toolbar>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-slate-900">Akan disusutkan sampai {{ Format::bulan($period.'-01') }}</h2>
                    <p class="text-sm text-slate-500">{{ $preview->count() }} aset · {{ Format::rupiah(Uang::keDesimal($total)) }}</p>
                </div>
                @if ($preview->isNotEmpty() && auth()->user()->can('accounting.assets.approve'))
                    <form method="POST" action="{{ route('depreciation.store') }}">
                        @csrf
                        <input type="hidden" name="periode" value="{{ $period }}">
                        <x-ui.button type="submit" icon="check">Hitung &amp; jurnal</x-ui.button>
                    </form>
                @endif
            </div>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Aset</x-ui.table.th>
            <x-ui.table.th>Bulan</x-ui.table.th>
            <x-ui.table.th align="right">Penyusutan</x-ui.table.th>
            <x-ui.table.th align="right">Nilai buku setelahnya</x-ui.table.th>
        </x-slot:head>
        @forelse ($preview as $item)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('fixed-assets.show', $item['asset']) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $item['asset']->name }}</a>
                    <p class="text-xs text-slate-500">{{ $item['asset']->code }} · {{ $item['asset']->category?->name }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>
                    {{ collect($item['rows'])->map(fn ($r) => Format::bulan($r['period'].'-01'))->join(', ') }}
                    @if (count($item['rows']) > 1)
                        <x-ui.badge color="warning" class="ml-1">Susulan</x-ui.badge>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ Format::rupiah(Uang::keDesimal($item['total'])) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ Format::rupiah(Uang::keDesimal(end($item['rows'])['book_value'])) }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="4" icon="check-circle" title="Tidak ada yang perlu disusutkan" description="Semua aset aktif sudah disusutkan sampai periode ini, atau sudah habis umurnya." />
        @endforelse
    </x-ui.table>

    <x-ui.table :paginator="$runs" :sticky="false">
        <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Riwayat penyusutan</h2></x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Periode</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right">Aset</x-ui.table.th>
            <x-ui.table.th align="right">Total</x-ui.table.th>
        </x-slot:head>
        @forelse ($runs as $run)
            <tr @class(['opacity-60' => $run->isCancelled()])>
                <x-ui.table.td><a href="{{ route('depreciation-runs.show', $run) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ Format::bulan($run->period.'-01') }}</a></x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$run->isCancelled() ? 'danger' : 'success'" dot>{{ $run->isCancelled() ? 'Dibatalkan' : 'Dijurnal' }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $run->asset_count }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($run->total)</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="4" icon="calendar" title="Belum ada penyusutan" />
        @endforelse
    </x-ui.table>
</x-app-layout>
