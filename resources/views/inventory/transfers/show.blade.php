{{-- Detail transfer stok: rincian barang, surat jalan, dan pembatalan. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Transfer {{ $transfer->number }}</x-slot>
    <x-slot name="description">{{ $transfer->fromWarehouse->name }} → {{ $transfer->toWarehouse->name }} · {{ Format::tanggal($transfer->transfer_date) }}</x-slot>
    <x-slot name="actions">
        @can('inventory.transfers.print')
            <x-ui.button variant="secondary" icon="printer" :href="route('stock-transfers.print', $transfer)" target="_blank">Surat jalan</x-ui.button>
        @endcan
        @if (! $transfer->dibatalkan() && auth()->user()->can('inventory.transfers.void'))
            <x-ui.button variant="danger" icon="x-circle" x-data x-on:click="$dispatch('open-modal', 'batal-transfer')">Batalkan</x-ui.button>
        @endif
    </x-slot>

    @if ($transfer->dibatalkan())
        <x-ui.alert type="danger" class="mb-6" title="Transfer ini dibatalkan">
            Oleh {{ $transfer->voidedBy?->name }} pada {{ Format::tanggal($transfer->voided_at, true) }}. Alasan: {{ $transfer->void_reason }}.
            Stok sudah dikembalikan ke {{ $transfer->fromWarehouse->name }}.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian" class="lg:col-span-1">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$transfer->status->color()" dot>{{ $transfer->status->label() }}</x-ui.badge></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($transfer->transfer_date) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Dari</dt><dd class="text-right text-slate-900">{{ $transfer->fromWarehouse->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Ke</dt><dd class="text-right text-slate-900">{{ $transfer->toWarehouse->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Dibuat oleh</dt><dd class="text-right text-slate-900">{{ $transfer->user?->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Dicatat</dt><dd class="text-right text-slate-900">{{ Format::tanggal($transfer->created_at, true) }}</dd></div>
                @if ($transfer->note)
                    <div><dt class="text-slate-500">Catatan</dt><dd class="mt-1 text-slate-900">{{ $transfer->note }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Jumlah</x-ui.table.th>
            </x-slot:head>
            @foreach ($transfer->items as $item)
                <tr>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                        <p class="text-xs text-slate-500">{{ $item->sku }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}</x-ui.table.td>
                </tr>
            @endforeach
            <x-slot:footer>
                <div class="flex justify-between text-sm">
                    <span class="text-slate-500">{{ $transfer->items->count() }} produk</span>
                    @can('inventory.stock-card.view')
                        <a href="{{ route('stock.movements', ['gudang' => $transfer->to_warehouse_id]) }}" class="text-primary-700 hover:underline">Lihat kartu stok gudang tujuan</a>
                    @endcan
                </div>
            </x-slot:footer>
        </x-ui.table>
    </div>

    @if (! $transfer->dibatalkan() && auth()->user()->can('inventory.transfers.void'))
        <x-ui.void-dialog name="batal-transfer" :action="route('stock-transfers.void', $transfer)" :title="'Batalkan '.$transfer->number.'?'">
            Stok dikurangi dari {{ $transfer->toWarehouse->name }} dan dikembalikan ke {{ $transfer->fromWarehouse->name }}.
            Bila sebagian barang sudah keluar dari gudang tujuan, pembatalan ditolak. Dokumen tidak dihapus.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
