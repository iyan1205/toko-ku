{{-- Piutang pelanggan: penjualan yang belum lunas, terlama dulu, dan pelunasannya (boleh dicicil). --}}
@use('App\Support\Format')
@use('App\Exports\Pos\ReceivablesExport')
@php
    $today = now()->startOfDay();
    $awalTarget = [
        'url' => old('_piutang_url', ''),
        'nomor' => old('_piutang_nomor', ''),
        'pelanggan' => old('_piutang_pelanggan', ''),
        'sisa' => (float) old('_piutang_sisa', 0),
        'jumlah' => old('amount', ''),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Piutang Pelanggan</x-slot>
    <x-slot name="description">Penjualan yang belum lunas. Pelunasan bisa dicicil.</x-slot>
    <x-slot name="actions">
        @can('pos.receivables.export')
            <x-ui.export-menu :href="route('receivables.export')" />
        @endcan
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Total piutang" :value="Format::rupiah($summary['total'])" icon="banknotes" color="neutral"
                        :hint="Format::jumlah($summary['count']).' transaksi, '.Format::jumlah($summary['customers']).' pelanggan'" />
        <x-ui.stat-card label="Lebih dari 30 hari" :value="Format::rupiah($summary['old'])" icon="exclamation-triangle" color="danger"
                        :hint="Format::jumlah($summary['old_count']).' transaksi'" :href="route('receivables.index', ['umur' => 'lama'])" />
        <x-ui.stat-card label="Rata-rata per transaksi" :value="Format::rupiah($summary['count'] > 0 ? $summary['total'] / $summary['count'] : 0)" icon="calculator" color="neutral" />
    </div>

    <div x-data="{ target: {{ \Illuminate\Support\Js::from($awalTarget) }} }">
        <x-ui.table :paginator="$receivables">
            <x-slot:toolbar>
                <x-ui.filter-bar placeholder="Cari nomor penjualan atau nama pelanggan">
                    <x-ui.select name="pelanggan" label="Pelanggan" hide-label :options="$customers->pluck('name', 'id')" :selected="request('pelanggan')" placeholder="Semua pelanggan" />
                    <x-ui.select name="umur" label="Umur piutang" hide-label :options="ReceivablesExport::AGE_FILTERS" :selected="request('umur')" placeholder="Semua umur" />
                    <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                    <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
                </x-ui.filter-bar>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.table.th sort="number">Penjualan</x-ui.table.th>
                <x-ui.table.th sort="customer">Pelanggan</x-ui.table.th>
                <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
                <x-ui.table.th align="right">Total</x-ui.table.th>
                <x-ui.table.th align="right" sort="balance">Sisa piutang</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>

            @forelse ($receivables as $p)
                @php($umur = (int) $p->sold_at->copy()->startOfDay()->diffInDays($today))
                <tr>
                    <x-ui.table.td>
                        @can('view', $p)
                            <a href="{{ route('sales.show', $p) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->number }}</a>
                        @else
                            <span class="font-medium text-slate-900">{{ $p->number }}</span>
                        @endcan
                        <p class="text-xs text-slate-500">kasir {{ $p->user?->name }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        @can('pos.customers.view')
                            <a href="{{ route('customers.show', $p->customer_id) }}" class="text-slate-900 hover:underline">{{ $p->customer?->name }}</a>
                        @else
                            {{ $p->customer?->name }}
                        @endcan
                        @if ($p->customer?->phone)
                            <p class="text-xs text-slate-500">{{ $p->customer->phone }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <p class="tabular-nums text-slate-700">{{ Format::tanggal($p->sold_at) }}</p>
                        <x-ui.badge :color="$umur > 30 ? 'danger' : ($umur > 7 ? 'warning' : 'neutral')">{{ $umur === 0 ? 'Hari ini' : $umur.' hari' }}</x-ui.badge>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>@rupiah($p->grand_total)</x-ui.table.td>
                    <x-ui.table.td align="right" numeric class="font-medium text-slate-900">@rupiah($p->balance)</x-ui.table.td>
                    <x-ui.table.td align="right">
                        @can('pos.receivables.create')
                            <x-ui.button size="sm" variant="secondary" icon="banknotes"
                                x-on:click="target = { url: {{ \Illuminate\Support\Js::from(route('receivables.store', $p)) }}, nomor: {{ \Illuminate\Support\Js::from($p->number) }}, pelanggan: {{ \Illuminate\Support\Js::from($p->customer?->name) }}, sisa: {{ (float) $p->balance }}, jumlah: {{ (float) $p->balance }} }; $dispatch('open-modal', 'terima-piutang')">Terima</x-ui.button>
                        @endcan
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="6" icon="check-circle" title="Tidak ada piutang yang cocok" description="Semua penjualan sudah lunas, atau ubah filter." />
            @endforelse
        </x-ui.table>

        @can('pos.receivables.create')
            @include('partials.terima-piutang', ['methods' => $methods])
        @endcan
    </div>
</x-app-layout>
