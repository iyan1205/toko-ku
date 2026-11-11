{{-- Faktur penjualan manual (piutang) / tagihan pembelian manual (hutang), termasuk faktur dari saldo awal. --}}
@use('App\Support\Format')
@use('App\Enums\InvoiceType')
@php
    $penjualan = $type === InvoiceType::Sales;
    $prefix = $type->routePrefix();
@endphp

<x-app-layout>
    <x-slot name="header">{{ $penjualan ? 'Faktur Penjualan' : 'Tagihan Pembelian' }}</x-slot>
    <x-slot name="description">{{ $penjualan
        ? 'Tagihan ke pelanggan di luar penjualan kasir, misal jasa, sewa, atau katering. Juga faktur lama dari saldo awal.'
        : 'Tagihan dari supplier di luar pembelian barang, misal jasa, sewa, atau perbaikan. Juga hutang lama dari saldo awal.' }}</x-slot>
    <x-slot name="actions">
        @can($type->permission('export'))
            <x-ui.export-menu :href="route($prefix.'.export')" />
        @endcan
        @can($type->permission('create'))
            <x-ui.button :href="route($prefix.'.create')" icon="plus">{{ $penjualan ? 'Faktur baru' : 'Tagihan baru' }}</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$invoices">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor, referensi, atau keterangan">
                <x-ui.select name="pihak" :label="$penjualan ? 'Pelanggan' : 'Supplier'" hide-label :options="$parties" :selected="request('pihak')" :placeholder="$penjualan ? 'Semua pelanggan' : 'Semua supplier'" />
                <x-ui.select name="status" label="Status" hide-label :options="['belum_lunas' => 'Belum lunas', 'lunas' => 'Lunas', 'draft' => 'Draf', 'posted' => 'Diposting', 'cancelled' => 'Dibatalkan']" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th sort="due">Jatuh tempo</x-ui.table.th>
            <x-ui.table.th>{{ $penjualan ? 'Pelanggan' : 'Supplier' }}</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right" sort="total">Total</x-ui.table.th>
            <x-ui.table.th align="right" sort="balance">Sisa</x-ui.table.th>
        </x-slot:head>
        @forelse ($invoices as $inv)
            @php
                $lewat = $inv->isPosted() && (float) $inv->balance > 0 && $inv->due_date?->lt(today());
            @endphp
            <tr>
                <x-ui.table.td>
                    <a href="{{ route($prefix.'.show', $inv) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $inv->number }}</a>
                    @if ($inv->isOpening())
                        <x-ui.badge color="neutral" class="ml-1">Saldo awal</x-ui.badge>
                    @endif
                    @if ($inv->reference)
                        <p class="text-xs text-slate-500">{{ $inv->reference }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($inv->invoice_date) }}</x-ui.table.td>
                <x-ui.table.td numeric :class="$lewat ? 'font-medium text-danger-700' : 'text-slate-500'">{{ $inv->due_date ? Format::tanggal($inv->due_date) : '—' }}</x-ui.table.td>
                <x-ui.table.td>{{ $inv->party()?->name }}</x-ui.table.td>
                <x-ui.table.td>
                    @if ($inv->isPosted())
                        <x-ui.badge :color="(float) $inv->balance <= 0 ? 'success' : ($lewat ? 'danger' : 'warning')" dot>{{ (float) $inv->balance <= 0 ? 'Lunas' : ($lewat ? 'Lewat jatuh tempo' : $inv->payment_status->label()) }}</x-ui.badge>
                    @else
                        <x-ui.badge :color="$inv->documentStatus()->color()" dot>{{ $inv->documentStatus()->label() }}</x-ui.badge>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($inv->total)</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium">{{ $inv->isPosted() ? Format::rupiah($inv->balance) : '—' }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="document-text" :title="$penjualan ? 'Belum ada faktur penjualan' : 'Belum ada tagihan pembelian'"
                              :description="$penjualan ? 'Penjualan kasir dengan piutang tetap di halaman Piutang Pelanggan. Di sini untuk tagihan non-stok.' : 'Faktur pembelian barang tetap di halaman Faktur Pembelian. Di sini untuk tagihan non-stok.'" />
        @endforelse
    </x-ui.table>
</x-app-layout>
