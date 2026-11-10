{{-- Detail purchase order: barang dipesan/diterima, penerimaan, dan aksi sesuai status. --}}
@use('App\Support\Format')
@use('App\Enums\PurchaseOrderStatus')
@php
    $u = auth()->user();
    $status = $order->status;
    $adaPenerimaan = $order->receipts->contains(fn ($r) => ! $r->dibatalkan());
@endphp

<x-app-layout>
    <x-slot name="header">Purchase order {{ $order->number }}</x-slot>
    <x-slot name="description">{{ $order->supplier->name }} · {{ Format::tanggal($order->order_date) }}</x-slot>
    <x-slot name="actions">
        @if ($status !== PurchaseOrderStatus::Cancelled && $u->can('purchasing.orders.print'))
            <x-ui.button variant="secondary" icon="printer" :href="route('purchase-orders.print', $order)" target="_blank">Cetak PO</x-ui.button>
        @endif
        @if ($status === PurchaseOrderStatus::Draft)
            @can('purchasing.orders.delete')
                <x-ui.button variant="ghost" icon="trash" class="text-danger-600 hover:text-danger-700"
                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('purchase-orders.destroy', $order)) }}, nama: {{ \Illuminate\Support\Js::from('Draft '.$order->number) }} })">Hapus</x-ui.button>
            @endcan
            @can('purchasing.orders.update')
                <x-ui.button variant="secondary" icon="pencil-square" :href="route('purchase-orders.edit', $order)">Ubah</x-ui.button>
            @endcan
            @can('purchasing.orders.approve')
                <form method="POST" action="{{ route('purchase-orders.approve', $order) }}" x-data="{ kirim: false }" x-on:submit="kirim = true">
                    @csrf
                    <x-ui.button type="submit" icon="check" x-bind:disabled="kirim">Setujui</x-ui.button>
                </form>
            @endcan
        @endif
        @if ($status->canReceive())
            @can('purchasing.orders.approve')
                @if ($status === PurchaseOrderStatus::Partial)
                    <x-ui.button variant="secondary" icon="lock-closed" x-data x-on:click="$dispatch('open-modal', 'tutup-po')">Tutup PO</x-ui.button>
                @elseif (! $adaPenerimaan)
                    <x-ui.button variant="ghost" icon="x-circle" class="text-danger-600 hover:text-danger-700" x-data x-on:click="$dispatch('open-modal', 'batal-po')">Batalkan</x-ui.button>
                @endif
            @endcan
            @can('purchasing.receipts.create')
                <x-ui.button :href="route('goods-receipts.create', ['po' => $order->id])" icon="inbox-arrow-down">Terima barang</x-ui.button>
            @endcan
        @endif
    </x-slot>

    @if ($status === PurchaseOrderStatus::Cancelled)
        <x-ui.alert type="danger" class="mb-6" title="Purchase order dibatalkan">
            Oleh {{ $order->cancelledBy?->name }} pada {{ Format::tanggal($order->cancelled_at, true) }}. Alasan: {{ $order->cancel_reason }}.
        </x-ui.alert>
    @elseif ($order->closed_at)
        <x-ui.alert type="warning" class="mb-6" title="Ditutup sebelum diterima semua">
            Oleh {{ $order->closedBy?->name }} pada {{ Format::tanggal($order->closed_at, true) }}. Alasan: {{ $order->cancel_reason }}.
        </x-ui.alert>
    @elseif ($status === PurchaseOrderStatus::Draft)
        <x-ui.alert class="mb-6">Draft belum dikirim ke supplier. Setujui PO agar barangnya bisa diterima.</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$status->color()" dot>{{ $status->label() }}</x-ui.badge></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier</dt><dd class="text-right"><a href="{{ route('suppliers.show', $order->supplier) }}" class="text-primary-700 hover:underline">{{ $order->supplier->name }}</a></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Gudang tujuan</dt><dd class="text-right text-slate-900">{{ $order->warehouse->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Tanggal PO</dt><dd class="text-slate-900">{{ Format::tanggal($order->order_date) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Perkiraan tiba</dt><dd class="text-slate-900">{{ Format::tanggal($order->expected_date) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Dibuat oleh</dt><dd class="text-right text-slate-900">{{ $order->user?->name }}</dd></div>
                @if ($order->approved_at)
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Disetujui</dt><dd class="text-right text-slate-900">{{ $order->approvedBy?->name }}<br><span class="text-xs text-slate-500">{{ Format::tanggal($order->approved_at, true) }}</span></dd></div>
                @endif
                @if ($order->note)
                    <div><dt class="text-slate-500">Catatan</dt><dd class="mt-1 whitespace-pre-line text-slate-900">{{ $order->note }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            <x-ui.table :sticky="false">
                <x-slot:head>
                    <x-ui.table.th>Produk</x-ui.table.th>
                    <x-ui.table.th align="right">Dipesan</x-ui.table.th>
                    <x-ui.table.th align="right">Diterima</x-ui.table.th>
                    <x-ui.table.th align="right">Harga</x-ui.table.th>
                    <x-ui.table.th align="right">Subtotal</x-ui.table.th>
                </x-slot:head>
                @foreach ($order->items as $item)
                    @php
                        $sisa = $item->remainingQuantity();
                    @endphp
                    <tr>
                        <x-ui.table.td>
                            <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-500">{{ $item->sku }}</p>
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>
                            <span @class(['font-medium', 'text-success-700' => $sisa <= 0, 'text-slate-900' => $sisa > 0])>{{ Format::jumlah($item->received_quantity) }}</span>
                            @if ($sisa > 0 && $status !== PurchaseOrderStatus::Draft)
                                <p class="text-xs text-warning-700">sisa {{ Format::jumlah($sisa) }}</p>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric muted>
                            @rupiah($item->unit_price)
                            @if ((float) $item->discount > 0)
                                <p class="text-xs text-danger-600">diskon @rupiah($item->discount)</p>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($item->line_total)</x-ui.table.td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <dl class="ml-auto grid max-w-xs gap-1.5 text-sm">
                        <div class="flex justify-between gap-8"><dt class="text-slate-500">Subtotal</dt><dd class="tabular-nums">@rupiah($order->subtotal)</dd></div>
                        @if ((float) $order->item_discount > 0)
                            <div class="flex justify-between gap-8"><dt class="text-slate-500">Diskon baris</dt><dd class="tabular-nums text-danger-600">− @rupiah($order->item_discount)</dd></div>
                        @endif
                        <div class="flex justify-between gap-8"><dt class="text-slate-500">Diskon</dt><dd class="tabular-nums text-danger-600">− @rupiah($order->discount)</dd></div>
                        <div class="flex justify-between gap-8"><dt class="text-slate-500">Pajak</dt><dd class="tabular-nums">@rupiah($order->tax)</dd></div>
                        <div class="flex justify-between gap-8"><dt class="text-slate-500">Biaya lain</dt><dd class="tabular-nums">@rupiah($order->other_cost)</dd></div>
                        <div class="flex justify-between gap-8 border-t border-slate-200 pt-1.5 font-semibold text-slate-900"><dt>Total</dt><dd class="tabular-nums">@rupiah($order->grand_total)</dd></div>
                    </dl>
                </x-slot:footer>
            </x-ui.table>

            <x-ui.table :sticky="false">
                <x-slot:head>
                    <x-ui.table.th>Penerimaan</x-ui.table.th>
                    <x-ui.table.th>Tanggal</x-ui.table.th>
                    <x-ui.table.th>Gudang</x-ui.table.th>
                    <x-ui.table.th>Faktur</x-ui.table.th>
                    <x-ui.table.th align="right">Nilai</x-ui.table.th>
                    <x-ui.table.th>Status</x-ui.table.th>
                </x-slot:head>
                @forelse ($order->receipts as $r)
                    <tr @class(['text-slate-400' => $r->dibatalkan()])>
                        <x-ui.table.td><a href="{{ route('goods-receipts.show', $r) }}" class="font-medium text-slate-900 hover:underline">{{ $r->number }}</a></x-ui.table.td>
                        <x-ui.table.td numeric muted>{{ Format::tanggal($r->received_date) }}</x-ui.table.td>
                        <x-ui.table.td muted>{{ $r->warehouse?->name }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($r->purchase)
                                <a href="{{ route('purchases.show', $r->purchase) }}" class="text-primary-700 hover:underline">{{ $r->purchase->number }}</a>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($r->purchase?->grand_total ?? 0)</x-ui.table.td>
                        <x-ui.table.td><x-ui.badge :color="$r->status->color()">{{ $r->status->label() }}</x-ui.badge></x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="6" icon="inbox-arrow-down" title="Belum ada barang yang diterima" />
                @endforelse
            </x-ui.table>
        </div>
    </div>

    @can('purchasing.orders.approve')
        <x-ui.void-dialog name="batal-po" :action="route('purchase-orders.cancel', $order)" :title="'Batalkan '.$order->number.'?'">
            PO dibatalkan dan barangnya tidak bisa diterima lagi. Dokumen tetap tersimpan.
        </x-ui.void-dialog>
        <x-ui.void-dialog name="tutup-po" :action="route('purchase-orders.close', $order)" :title="'Tutup '.$order->number.'?'" confirm="Ya, tutup PO" label="Alasan penutupan">
            Sisa barang yang belum diterima tidak lagi ditunggu. Penerimaan dan hutang yang sudah tercatat tidak berubah.
        </x-ui.void-dialog>
    @endcan
</x-app-layout>
