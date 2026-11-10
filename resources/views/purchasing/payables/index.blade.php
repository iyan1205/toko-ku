{{-- Hutang supplier: faktur yang masih punya sisa hutang, jatuh tempo terdekat dulu, dan pembayarannya. --}}
@use('App\Support\Format')
@use('App\Exports\Purchasing\PayablesExport')
@php
    $today = now()->startOfDay();
    $awalTarget = [
        'url' => old('_bayar_url', ''),
        'nomor' => old('_bayar_nomor', ''),
        'sisa' => (float) old('_bayar_sisa', 0),
        'jumlah' => old('amount', ''),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Hutang Supplier</x-slot>
    <x-slot name="description">Faktur pembelian yang belum lunas. Pembayaran bisa dicicil.</x-slot>
    <x-slot name="actions">
        @can('purchasing.payables.export')
            <x-ui.export-menu :href="route('payables.export')" />
        @endcan
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Total hutang" :value="Format::rupiah($summary['total'])" icon="banknotes" color="neutral"
                        :hint="Format::jumlah($summary['count']).' faktur belum lunas'" />
        <x-ui.stat-card label="Sudah jatuh tempo" :value="Format::rupiah($summary['overdue'])" icon="exclamation-triangle" color="danger"
                        :hint="Format::jumlah($summary['overdue_count']).' faktur, termasuk hari ini'" :href="route('payables.index', ['jatuh_tempo' => 'lewat'])" />
        <x-ui.stat-card label="Jatuh tempo 7 hari ke depan" :value="Format::rupiah($summary['week'])" icon="clock" color="warning"
                        :href="route('payables.index', ['jatuh_tempo' => 'minggu'])" />
    </div>

    <div x-data="{ target: {{ \Illuminate\Support\Js::from($awalTarget) }} }">
        <x-ui.table :paginator="$payables">
            <x-slot:toolbar>
                <x-ui.filter-bar placeholder="Cari nomor faktur, faktur supplier, atau supplier">
                    <x-ui.select name="supplier" label="Supplier" hide-label :options="$suppliers->pluck('name', 'id')" :selected="request('supplier')" placeholder="Semua supplier" />
                    <x-ui.select name="jatuh_tempo" label="Jatuh tempo" hide-label :options="PayablesExport::DUE_FILTERS" :selected="request('jatuh_tempo')" placeholder="Semua jatuh tempo" />
                    <x-ui.input name="dari" label="Dari tanggal faktur" hide-label type="date" :value="request('dari')" />
                    <x-ui.input name="sampai" label="Sampai tanggal faktur" hide-label type="date" :value="request('sampai')" />
                </x-ui.filter-bar>
            </x-slot:toolbar>

            <x-slot:head>
                <x-ui.table.th sort="number">Faktur</x-ui.table.th>
                <x-ui.table.th sort="supplier">Supplier</x-ui.table.th>
                <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
                <x-ui.table.th sort="due">Jatuh tempo</x-ui.table.th>
                <x-ui.table.th align="right">Total</x-ui.table.th>
                <x-ui.table.th align="right" sort="balance">Sisa hutang</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>

            @forelse ($payables as $p)
                @php
                    $hari = $p->due_date ? (int) $today->diffInDays($p->due_date->copy()->startOfDay(), false) : null;
                @endphp
                <tr>
                    <x-ui.table.td>
                        <a href="{{ route('purchases.show', $p) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->number }}</a>
                        @if ($p->supplier_invoice_number)
                            <p class="text-xs text-slate-500">Faktur supplier {{ $p->supplier_invoice_number }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td>{{ $p->supplier?->name }}</x-ui.table.td>
                    <x-ui.table.td numeric muted>{{ Format::tanggal($p->purchase_date) }}</x-ui.table.td>
                    <x-ui.table.td>
                        @if ($hari === null)
                            <span class="text-slate-400">—</span>
                        @else
                            <p class="tabular-nums text-slate-700">{{ Format::tanggal($p->due_date) }}</p>
                            @if ($hari < 0)
                                <x-ui.badge color="danger">Lewat {{ abs($hari) }} hari</x-ui.badge>
                            @elseif ($hari === 0)
                                <x-ui.badge color="danger">Hari ini</x-ui.badge>
                            @elseif ($hari <= 7)
                                <x-ui.badge color="warning">{{ $hari }} hari lagi</x-ui.badge>
                            @endif
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>@rupiah($p->grand_total)</x-ui.table.td>
                    <x-ui.table.td align="right" numeric class="font-medium text-slate-900">@rupiah($p->balance)</x-ui.table.td>
                    <x-ui.table.td align="right">
                        @can('purchasing.payables.create')
                            <x-ui.button size="sm" variant="secondary" icon="banknotes"
                                x-on:click="target = { url: {{ \Illuminate\Support\Js::from(route('payables.store', $p)) }}, nomor: {{ \Illuminate\Support\Js::from($p->number) }}, sisa: {{ (float) $p->balance }}, jumlah: {{ (float) $p->balance }} }; $dispatch('open-modal', 'bayar-hutang')">Bayar</x-ui.button>
                        @endcan
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="7" icon="check-circle" title="Tidak ada hutang yang cocok" description="Semua faktur sudah lunas, atau ubah filter." />
            @endforelse
        </x-ui.table>

        @can('purchasing.payables.create')
            @include('partials.bayar-hutang', ['methods' => $methods])
        @endcan
    </div>
</x-app-layout>
