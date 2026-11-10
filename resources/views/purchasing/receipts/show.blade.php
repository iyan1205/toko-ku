{{-- Detail penerimaan barang: barang yang diterima, faktur/hutang yang dibuat, dan pembatalan. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Penerimaan {{ $receipt->number }}</x-slot>
    <x-slot name="description">{{ $receipt->supplier->name }} · {{ $receipt->warehouse->name }} · {{ Format::tanggal($receipt->received_date) }}</x-slot>
    <x-slot name="actions">
        @if (! $receipt->dibatalkan() && auth()->user()->can('purchasing.receipts.void'))
            <x-ui.button variant="danger" icon="x-circle" x-data x-on:click="$dispatch('open-modal', 'batal-penerimaan')">Batalkan</x-ui.button>
        @endif
    </x-slot>

    @if ($receipt->dibatalkan())
        <x-ui.alert type="danger" class="mb-6" title="Penerimaan ini dibatalkan">
            Oleh {{ $receipt->voidedBy?->name }} pada {{ Format::tanggal($receipt->voided_at, true) }}. Alasan: {{ $receipt->void_reason }}.
            Stok sudah dikurangi kembali dan fakturnya dibatalkan.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$receipt->status->color()" dot>{{ $receipt->status->label() }}</x-ui.badge></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Purchase order</dt><dd><a href="{{ route('purchase-orders.show', $receipt->purchaseOrder) }}" class="text-primary-700 hover:underline">{{ $receipt->purchaseOrder->number }}</a></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier</dt><dd class="text-right text-slate-900">{{ $receipt->supplier->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Gudang</dt><dd class="text-right text-slate-900">{{ $receipt->warehouse->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Tanggal terima</dt><dd class="text-slate-900">{{ Format::tanggal($receipt->received_date) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Surat jalan</dt><dd class="text-slate-900">{{ $receipt->delivery_note_number ?: '—' }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Diterima oleh</dt><dd class="text-right text-slate-900">{{ $receipt->user?->name }}</dd></div>
                @if ($receipt->note)
                    <div><dt class="text-slate-500">Catatan</dt><dd class="mt-1 text-slate-900">{{ $receipt->note }}</dd></div>
                @endif
            </dl>

            @if ($receipt->purchase)
                <div class="mt-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm">
                    <p class="text-xs uppercase tracking-wide text-slate-500">Faktur & hutang</p>
                    <a href="{{ route('purchases.show', $receipt->purchase) }}" class="mt-1 block font-semibold text-primary-700 hover:underline">{{ $receipt->purchase->number }}</a>
                    <div class="mt-2 flex justify-between"><span class="text-slate-600">Nilai</span><span class="tabular-nums text-slate-900">@rupiah($receipt->purchase->grand_total)</span></div>
                    <div class="flex justify-between"><span class="text-slate-600">Sisa hutang</span><span class="tabular-nums font-medium text-slate-900">@rupiah($receipt->purchase->balance)</span></div>
                    <div class="flex justify-between"><span class="text-slate-600">Jatuh tempo</span><span class="text-slate-900">{{ Format::tanggal($receipt->purchase->due_date) }}</span></div>
                </div>
            @endif
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Diterima</x-ui.table.th>
                <x-ui.table.th align="right">Harga</x-ui.table.th>
                <x-ui.table.th align="right">Diskon</x-ui.table.th>
                <x-ui.table.th align="right">Subtotal</x-ui.table.th>
            </x-slot:head>
            @foreach ($receipt->items as $item)
                <tr>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                        <p class="text-xs text-slate-500">{{ $item->sku }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>@rupiah($item->unit_price)</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>{{ (float) $item->discount > 0 ? \App\Support\Format::rupiah($item->discount) : '—' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>@rupiah($item->line_total)</x-ui.table.td>
                </tr>
            @endforeach
            <x-slot:footer>
                <p class="text-xs text-slate-500">Diskon, pajak, dan biaya lain PO dibagi ke penerimaan ini sebanding nilai barangnya; rinciannya ada di faktur.</p>
            </x-slot:footer>
        </x-ui.table>
    </div>

    @if (! $receipt->dibatalkan() && auth()->user()->can('purchasing.receipts.void'))
        <x-ui.void-dialog name="batal-penerimaan" :action="route('goods-receipts.void', $receipt)" :title="'Batalkan '.$receipt->number.'?'">
            Stok dikurangi dari {{ $receipt->warehouse->name }}, jumlah diterima di PO berkurang, dan faktur {{ $receipt->purchase?->number }} dibatalkan
            beserta pembayarannya. Ditolak bila barang sudah keluar dari gudang atau fakturnya sudah diretur.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
