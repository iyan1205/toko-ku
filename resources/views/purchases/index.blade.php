{{-- Faktur pembelian: pembelian langsung dan faktur dari penerimaan barang. Cari, filter, urutkan, ekspor. --}}
@use('App\Support\Format')
@use('App\Enums\PaymentStatus')
@use('App\Enums\PurchaseStatus')

<x-app-layout>
    <x-slot name="header">Faktur Pembelian</x-slot>
    <x-slot name="description">Pembelian langsung dan faktur yang dibuat otomatis dari penerimaan barang.</x-slot>
    <x-slot name="actions">
        @can('purchasing.invoices.export')
            <x-ui.export-menu :href="route('purchases.export')" />
        @endcan
        @can('purchasing.invoices.create')
            <x-ui.button :href="route('purchases.create')" icon="plus">Pembelian langsung</x-ui.button>
        @endcan
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Faktur" :value="Format::jumlah($ringkasan['jumlah'])" icon="document-text" color="neutral" hint="sesuai filter, tanpa yang dibatalkan" />
        <x-ui.stat-card label="Total pembelian" :value="Format::rupiah($ringkasan['total'])" icon="shopping-bag" />
        <x-ui.stat-card label="Sisa hutang" :value="Format::rupiah($ringkasan['hutang'])" icon="banknotes" color="warning" :href="route('payables.index')" />
    </div>

    <x-ui.table :paginator="$pembelian">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor, faktur supplier, atau supplier">
                <x-ui.select name="supplier" label="Supplier" hide-label :options="$suppliers->pluck('name', 'id')" :selected="request('supplier')" placeholder="Semua supplier" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="pembayaran" label="Pembayaran" hide-label :options="collect(PaymentStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :selected="request('pembayaran')" placeholder="Semua pembayaran" />
                <x-ui.select name="status" label="Status" hide-label :options="collect(PurchaseStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="number">Nomor</x-ui.table.th>
            <x-ui.table.th sort="date">Tanggal</x-ui.table.th>
            <x-ui.table.th sort="supplier">Supplier</x-ui.table.th>
            <x-ui.table.th>Gudang</x-ui.table.th>
            <x-ui.table.th align="right" sort="total">Total</x-ui.table.th>
            <x-ui.table.th align="right" sort="balance">Sisa hutang</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>

        @forelse ($pembelian as $p)
            <tr @class(['opacity-60' => $p->dibatalkan()])>
                <x-ui.table.td>
                    <a href="{{ route('purchases.show', $p) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->number }}</a>
                    <p class="text-xs text-slate-500">
                        {{ $p->purchaseOrder ? 'Dari '.$p->purchaseOrder->number : 'Pembelian langsung' }}@if ($p->supplier_invoice_number) · {{ $p->supplier_invoice_number }}@endif
                    </p>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($p->purchase_date) }}</x-ui.table.td>
                <x-ui.table.td>{{ $p->supplier?->name }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $p->warehouse?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($p->grand_total)</x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="! $p->dibatalkan() && (float) $p->balance > 0 ? 'font-medium text-slate-900' : 'text-slate-400'">@rupiah($p->balance)</x-ui.table.td>
                <x-ui.table.td>
                    @if ($p->dibatalkan())
                        <x-ui.badge :color="$p->status->color()" dot>{{ $p->status->label() }}</x-ui.badge>
                    @else
                        @include('partials.badge-pembayaran', ['status' => $p->payment_status])
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="document-text" title="Belum ada faktur yang cocok" description="Ubah filter, terima barang dari purchase order, atau catat pembelian langsung." />
        @endforelse
    </x-ui.table>
</x-app-layout>
