{{-- Daftar purchase order: draft, disetujui, diterima sebagian, selesai, dibatalkan. --}}
@use('App\Support\Format')
@use('App\Enums\PurchaseOrderStatus')

<x-app-layout>
    <x-slot name="header">Purchase Order</x-slot>
    <x-slot name="description">Pesanan ke supplier. Barang diterima lewat Penerimaan Barang, boleh bertahap.</x-slot>
    <x-slot name="actions">
        @can('purchasing.orders.export')
            <x-ui.export-menu :href="route('purchase-orders.export')" />
        @endcan
        @can('purchasing.orders.create')
            <x-ui.button :href="route('purchase-orders.create')" icon="plus">PO baru</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$orders">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor PO atau supplier">
                <x-ui.select name="supplier" label="Supplier" hide-label :options="$suppliers->pluck('name', 'id')" :selected="request('supplier')" placeholder="Semua supplier" />
                <x-ui.select name="status" label="Status" hide-label :options="collect(PurchaseOrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :selected="request('status')" placeholder="Semua status" />
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->pluck('name', 'id')" :selected="request('gudang')" placeholder="Semua gudang" />
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
            <x-ui.table.th>Diterima</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>

        @forelse ($orders as $o)
            @php($persen = $list->receivedPercent($o))
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('purchase-orders.show', $o) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $o->number }}</a>
                    @if ($o->expected_date && $o->status->canReceive())
                        <p @class(['text-xs', 'text-danger-600' => $o->expected_date->isPast(), 'text-slate-500' => ! $o->expected_date->isPast()])>Tiba {{ Format::tanggal($o->expected_date) }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($o->order_date) }}</x-ui.table.td>
                <x-ui.table.td>{{ $o->supplier?->name }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $o->warehouse?->name }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($o->grand_total)</x-ui.table.td>
                <x-ui.table.td>
                    <div class="flex items-center gap-2">
                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $persen }}" aria-valuemin="0" aria-valuemax="100" aria-label="Persentase diterima">
                            <div class="h-full rounded-full bg-primary-500" style="width: {{ min(100, $persen) }}%"></div>
                        </div>
                        <span class="text-xs tabular-nums text-slate-500">{{ Format::jumlah($persen) }}%</span>
                    </div>
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$o->status->color()" dot>{{ $o->status->label() }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="clipboard-document-list" title="Belum ada purchase order yang cocok" description="Ubah filter, atau buat PO baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>
