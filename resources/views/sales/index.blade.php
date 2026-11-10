{{-- Riwayat transaksi kasir: cari, filter, urutkan, ekspor. Kasir (view-own) hanya melihat transaksinya sendiri. --}}
@use('App\Support\Format')
@use('App\Exports\Pos\SalesExport')

<x-app-layout>
    <x-slot name="header">Riwayat Transaksi</x-slot>
    <x-slot name="description">{{ $hanyaMilikSendiri ? 'Transaksi yang Anda layani.' : 'Semua transaksi kasir, termasuk yang dibatalkan.' }}</x-slot>
    <x-slot name="actions">
        @can('pos.transactions.export')
            <x-ui.export-menu :href="route('sales.export')" />
        @endcan
        @can('pos.transactions.create')
            <x-ui.button :href="route('pos.index')" icon="shopping-cart">Buka Kasir</x-ui.button>
        @endcan
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Omzet" :value="Format::rupiah($ringkasan['omzet'])" icon="banknotes" hint="sesuai filter, tanpa yang dibatalkan" />
        <x-ui.stat-card label="Transaksi" :value="Format::jumlah($ringkasan['jumlah'])" icon="receipt-percent" color="neutral" />
        <x-ui.stat-card label="Retur" :value="Format::rupiah($ringkasan['retur'])" icon="arrow-uturn-left" color="neutral" />
        <x-ui.stat-card label="Piutang" :value="Format::rupiah($ringkasan['piutang'])" icon="clock" color="warning"
                        :href="auth()->user()->can('pos.receivables.view') ? route('receivables.index') : null" />
    </div>

    <x-ui.table :paginator="$penjualan">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor atau nama pelanggan">
                @unless ($hanyaMilikSendiri)
                    <x-ui.select name="kasir" label="Kasir" hide-label :options="$cashiers->pluck('name', 'id')" :selected="request('kasir')" placeholder="Semua kasir" />
                @endunless
                <x-ui.select name="pelanggan" label="Pelanggan" hide-label :options="$customers->pluck('name', 'id')" :selected="request('pelanggan')" placeholder="Semua pelanggan" />
                <x-ui.select name="metode" label="Metode" hide-label :options="$methods" :selected="request('metode')" placeholder="Semua metode" />
                <x-ui.select name="status" label="Status" hide-label :options="SalesExport::STATUSES" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Waktu</x-ui.table.th>
            @unless ($hanyaMilikSendiri)
                <x-ui.table.th>Kasir</x-ui.table.th>
            @endunless
            <x-ui.table.th sort="customer">Pelanggan</x-ui.table.th>
            <x-ui.table.th>Metode</x-ui.table.th>
            <x-ui.table.th align="right" sort="total">Total</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($penjualan as $p)
            <tr @class(['text-slate-400' => $p->dibatalkan()])>
                <x-ui.table.td>
                    <a href="{{ route('sales.show', $p) }}" @class(['font-medium hover:text-primary-700 hover:underline', 'text-slate-900' => ! $p->dibatalkan(), 'line-through' => $p->dibatalkan()])>{{ $p->number }}</a>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($p->sold_at, true) }}</x-ui.table.td>
                @unless ($hanyaMilikSendiri)
                    <x-ui.table.td>{{ $p->user?->name }}</x-ui.table.td>
                @endunless
                <x-ui.table.td>{{ $p->customer?->name }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $p->payment_method->label() }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @rupiah($p->grand_total)
                    @if ((float) $p->returned_amount > 0)
                        <p class="text-xs text-warning-700">retur @rupiah($p->returned_amount)</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td>
                    @if ($p->dibatalkan())
                        <x-ui.badge color="neutral">Dibatalkan</x-ui.badge>
                    @else
                        @include('partials.badge-pembayaran', ['status' => $p->payment_status, 'penjualan' => true])
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    @can('pos.transactions.print')
                        <x-ui.button variant="ghost" size="sm" icon="printer" :href="route('sales.receipt', $p)" target="_blank" rel="noopener" aria-label="Struk {{ $p->number }}" />
                    @endcan
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty :colspan="$hanyaMilikSendiri ? 7 : 8" icon="receipt-percent" title="Belum ada transaksi yang cocok" description="Ubah filter, atau mulai transaksi di Kasir." />
        @endforelse
    </x-ui.table>
</x-app-layout>
