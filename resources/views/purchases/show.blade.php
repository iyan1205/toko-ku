{{--
    Detail faktur pembelian: barang, angka faktur, pembayaran (bayar hutang di sini), retur, dan pembatalan.
    Faktur dari penerimaan barang dibatalkan lewat halaman penerimaannya.
--}}
@use('App\Support\Format')
@php
    $aktif = ! $pembelian->dibatalkan();
    $user = auth()->user();
    $bisaBayar = $aktif && (float) $pembelian->balance > 0 && $user->can('purchasing.payables.create');
    $bisaRetur = $aktif && $user->can('purchasing.returns.create') && $pembelian->items->contains(fn ($i) => $i->returnableQuantity() > 0);
    $bisaBatal = $aktif && ! $pembelian->fromReceipt() && $user->can('purchasing.invoices.void');
    $target = [
        'url' => route('payables.store', $pembelian),
        'nomor' => $pembelian->number,
        'sisa' => (float) $pembelian->balance,
        'jumlah' => old('amount', (float) $pembelian->balance),
    ];
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">Faktur {{ $pembelian->number }}</x-slot>
    <x-slot name="description">{{ $pembelian->supplier->name }} · {{ $pembelian->warehouse?->name }} · {{ Format::tanggal($pembelian->purchase_date) }}</x-slot>
    <x-slot name="actions">
        @if ($bisaRetur)
            <x-ui.button variant="secondary" icon="arrow-uturn-left" :href="route('purchase-returns.create', ['faktur' => $pembelian->id])">Retur barang</x-ui.button>
        @endif
        @if ($bisaBatal)
            <x-ui.button variant="danger" icon="x-circle" x-data x-on:click="$dispatch('open-modal', 'batal-faktur')">Batalkan</x-ui.button>
        @endif
        @if ($bisaBayar)
            <x-ui.button icon="banknotes" x-data x-on:click="$dispatch('open-modal', 'bayar-hutang')">Bayar hutang</x-ui.button>
        @endif
    </x-slot>

    @if (! $aktif)
        <x-ui.alert type="danger" class="mb-6" title="Faktur ini dibatalkan">
            Oleh {{ $pembelian->voidedBy?->name }} pada {{ Format::tanggal($pembelian->voided_at, true) }}. Alasan: {{ $pembelian->void_reason }}.
            Stok sudah dikurangi kembali dan pembayarannya ikut dibatalkan.
        </x-ui.alert>
    @elseif ($pembelian->fromReceipt())
        <x-ui.alert type="info" class="mb-6">
            Faktur ini dibuat otomatis dari penerimaan
            <a href="{{ route('goods-receipts.show', $pembelian->goodsReceipt) }}" class="font-medium underline">{{ $pembelian->goodsReceipt?->number }}</a>.
            Untuk membatalkannya, batalkan penerimaan barangnya.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt>
                    <dd>
                        @if ($aktif)
                            @include('partials.badge-pembayaran', ['status' => $pembelian->payment_status])
                        @else
                            <x-ui.badge :color="$pembelian->status->color()" dot>{{ $pembelian->status->label() }}</x-ui.badge>
                        @endif
                    </dd>
                </div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Supplier</dt>
                    <dd class="text-right"><a href="{{ route('suppliers.show', $pembelian->supplier) }}" class="text-primary-700 hover:underline">{{ $pembelian->supplier->name }}</a></dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Asal</dt>
                    <dd class="text-right text-slate-900">
                        @if ($pembelian->purchaseOrder)
                            <a href="{{ route('purchase-orders.show', $pembelian->purchaseOrder) }}" class="text-primary-700 hover:underline">{{ $pembelian->purchaseOrder->number }}</a>
                        @else
                            Pembelian langsung
                        @endif
                    </dd>
                </div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Gudang</dt><dd class="text-right text-slate-900">{{ $pembelian->warehouse?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($pembelian->purchase_date) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Jatuh tempo</dt>
                    <dd @class(['text-slate-900', 'font-medium text-danger-600' => $aktif && (float) $pembelian->balance > 0 && $pembelian->due_date?->isBefore(now()->startOfDay()->addDay())])>{{ Format::tanggal($pembelian->due_date) }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Faktur supplier</dt><dd class="text-right text-slate-900">{{ $pembelian->supplier_invoice_number ?: '—' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Dicatat oleh</dt><dd class="text-right text-slate-900">{{ $pembelian->user?->name }}</dd></div>
                @if ($pembelian->note)
                    <div><dt class="text-slate-500">Catatan</dt><dd class="mt-1 text-slate-900">{{ $pembelian->note }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            <x-ui.table :sticky="false">
                <x-slot:head>
                    <x-ui.table.th>Produk</x-ui.table.th>
                    <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                    <x-ui.table.th align="right">Harga</x-ui.table.th>
                    <x-ui.table.th align="right">Diskon</x-ui.table.th>
                    <x-ui.table.th align="right">Subtotal</x-ui.table.th>
                </x-slot:head>
                @foreach ($pembelian->items as $item)
                    <tr>
                        <x-ui.table.td>
                            <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-500">{{ $item->sku }}</p>
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>
                            {{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}
                            @if ((float) $item->returned_quantity > 0)
                                <p class="text-xs text-warning-700">diretur {{ Format::jumlah($item->returned_quantity) }}</p>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric muted>@rupiah($item->unit_price)</x-ui.table.td>
                        <x-ui.table.td align="right" numeric muted>{{ (float) $item->discount > 0 ? Format::rupiah($item->discount) : '—' }}</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($item->line_total)</x-ui.table.td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <dl class="ml-auto grid w-full max-w-sm gap-2 text-sm">
                        <div class="{{ $baris }}"><dt class="text-slate-600">Subtotal</dt><dd class="tabular-nums">@rupiah($pembelian->subtotal)</dd></div>
                        @if ((float) $pembelian->item_discount > 0)
                            <div class="{{ $baris }}"><dt class="text-slate-600">Diskon baris</dt><dd class="tabular-nums text-danger-600">− @rupiah($pembelian->item_discount)</dd></div>
                        @endif
                        <div class="{{ $baris }}"><dt class="text-slate-600">Diskon</dt><dd class="tabular-nums text-danger-600">− @rupiah($pembelian->discount)</dd></div>
                        <div class="{{ $baris }}"><dt class="text-slate-600">Pajak</dt><dd class="tabular-nums">@rupiah($pembelian->tax)</dd></div>
                        <div class="{{ $baris }}"><dt class="text-slate-600">Biaya lain</dt><dd class="tabular-nums">@rupiah($pembelian->other_cost)</dd></div>
                        <div class="{{ $baris }} border-t border-slate-200 pt-2 text-base font-semibold text-slate-900"><dt>Total</dt><dd class="tabular-nums">@rupiah($pembelian->grand_total)</dd></div>
                        <div class="{{ $baris }}"><dt class="text-slate-600">Dibayar</dt><dd class="tabular-nums">@rupiah($pembelian->paid_amount)</dd></div>
                        @if ((float) $pembelian->returned_amount > 0)
                            <div class="{{ $baris }}"><dt class="text-slate-600">Retur</dt><dd class="tabular-nums">− @rupiah($pembelian->returned_amount)</dd></div>
                        @endif
                        <div class="{{ $baris }} font-semibold {{ $aktif && (float) $pembelian->balance > 0 ? 'text-warning-700' : 'text-slate-900' }}"><dt>Sisa hutang</dt><dd class="tabular-nums">@rupiah($pembelian->balance)</dd></div>
                    </dl>
                </x-slot:footer>
            </x-ui.table>

            <x-ui.card title="Pembayaran" :padding="false">
                @if ($pembelian->payments->isEmpty())
                    <x-ui.empty-state icon="banknotes" title="Belum ada pembayaran" description="Pembayaran hutang faktur ini akan tampil di sini." />
                @else
                    <x-ui.table :sticky="false">
                        <x-slot:head>
                            <x-ui.table.th>Nomor</x-ui.table.th>
                            <x-ui.table.th>Tanggal</x-ui.table.th>
                            <x-ui.table.th>Metode</x-ui.table.th>
                            <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                            <x-ui.table.th>Keterangan</x-ui.table.th>
                        </x-slot:head>
                        @foreach ($pembelian->payments as $bayar)
                            <tr @class(['text-slate-400' => $bayar->dibatalkan()])>
                                <x-ui.table.td><span @class(['font-medium text-slate-900' => ! $bayar->dibatalkan(), 'line-through' => $bayar->dibatalkan()])>{{ $bayar->number }}</span></x-ui.table.td>
                                <x-ui.table.td numeric muted>{{ Format::tanggal($bayar->paid_at) }}</x-ui.table.td>
                                <x-ui.table.td>{{ $bayar->method->label() }}</x-ui.table.td>
                                <x-ui.table.td align="right" numeric>@rupiah($bayar->amount)</x-ui.table.td>
                                <x-ui.table.td muted>
                                    {{ $bayar->note ?: '—' }} · {{ $bayar->user?->name }}
                                    @if ($bayar->dibatalkan())
                                        <x-ui.badge color="neutral" class="ml-1">Dibatalkan</x-ui.badge>
                                    @endif
                                </x-ui.table.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>

            @if ($pembelian->returns->isNotEmpty())
                <x-ui.card title="Retur" :padding="false">
                    <x-ui.table :sticky="false">
                        <x-slot:head>
                            <x-ui.table.th>Nomor</x-ui.table.th>
                            <x-ui.table.th>Tanggal</x-ui.table.th>
                            <x-ui.table.th align="right">Nilai</x-ui.table.th>
                            <x-ui.table.th>Status</x-ui.table.th>
                        </x-slot:head>
                        @foreach ($pembelian->returns as $retur)
                            <tr>
                                <x-ui.table.td><a href="{{ route('purchase-returns.show', $retur) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $retur->number }}</a></x-ui.table.td>
                                <x-ui.table.td numeric muted>{{ Format::tanggal($retur->return_date) }}</x-ui.table.td>
                                <x-ui.table.td align="right" numeric>@rupiah($retur->total)</x-ui.table.td>
                                <x-ui.table.td><x-ui.badge :color="$retur->status->color()" dot>{{ $retur->status->label() }}</x-ui.badge></x-ui.table.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>
    </div>

    @if ($bisaBatal)
        <x-ui.void-dialog name="batal-faktur" :action="route('purchases.void', $pembelian)" :title="'Batalkan '.$pembelian->number.'?'">
            Stok dikurangi dari {{ $pembelian->warehouse?->name }} dan pembayarannya ikut dibatalkan. Ditolak bila barangnya sudah
            keluar dari gudang; gunakan Retur Pembelian untuk barang yang masih ada.
        </x-ui.void-dialog>
    @endif

    @if ($bisaBayar)
        <div x-data="{ target: {{ \Illuminate\Support\Js::from($target) }} }">
            @include('partials.bayar-hutang', ['methods' => $metode])
        </div>
    @endif
</x-app-layout>
