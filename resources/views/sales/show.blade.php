{{--
    Detail penjualan kasir: barang, angka transaksi (diskon, pajak, retur), pembayaran per metode,
    retur, pelunasan piutang, dan pembatalan. Kolom laba hanya untuk pemegang pos.transactions.view.
--}}
@use('App\Support\Format')
@php
    $aktif = ! $penjualan->dibatalkan();
    $user = auth()->user();
    $lihatSemua = $user->can('pos.transactions.view');
    $bisaLunasi = $aktif && (float) $penjualan->balance > 0 && $user->can('pos.receivables.create');
    $bisaRetur = $aktif && $user->can('pos.returns.create') && $penjualan->items->contains(fn ($i) => $i->returnableQuantity() > 0);
    $bisaBatal = $aktif && $user->can('void', $penjualan);
    $target = [
        'url' => route('receivables.store', $penjualan),
        'nomor' => $penjualan->number,
        'pelanggan' => $penjualan->customer?->name,
        'sisa' => (float) $penjualan->balance,
        'jumlah' => old('amount', (float) $penjualan->balance),
    ];
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">Penjualan {{ $penjualan->number }}</x-slot>
    <x-slot name="description">{{ $penjualan->customer?->name }} · {{ Format::tanggal($penjualan->sold_at, true) }} · kasir {{ $penjualan->user?->name }}</x-slot>
    <x-slot name="actions">
        @if ($bisaRetur)
            <x-ui.button variant="secondary" icon="arrow-uturn-left" :href="route('sale-returns.create', ['penjualan' => $penjualan->id])">Retur barang</x-ui.button>
        @endif
        @if ($bisaBatal)
            <x-ui.button variant="danger" icon="x-circle" x-data x-on:click="$dispatch('open-modal', 'batal-penjualan')">Batalkan</x-ui.button>
        @endif
        @if ($bisaLunasi)
            <x-ui.button variant="secondary" icon="banknotes" x-data x-on:click="$dispatch('open-modal', 'terima-piutang')">Terima pelunasan</x-ui.button>
        @endif
        @can('pos.transactions.print')
            <x-ui.button icon="printer" :href="route('sales.receipt', $penjualan)" target="_blank" rel="noopener">Cetak struk</x-ui.button>
        @endcan
    </x-slot>

    @if (! $aktif)
        <x-ui.alert type="danger" class="mb-6" title="Penjualan ini dibatalkan">
            Oleh {{ $penjualan->voidedBy?->name }} pada {{ Format::tanggal($penjualan->voided_at, true) }}. Alasan: {{ $penjualan->void_reason }}.
            Stok sudah dikembalikan dan pembayarannya ditandai batal. Dokumen tetap tersimpan sebagai riwayat.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt>
                    <dd>
                        @if ($aktif)
                            @include('partials.badge-pembayaran', ['status' => $penjualan->payment_status, 'penjualan' => true])
                        @else
                            <x-ui.badge color="neutral" dot>Dibatalkan</x-ui.badge>
                        @endif
                    </dd>
                </div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Pelanggan</dt>
                    <dd class="text-right">
                        @can('pos.customers.view')
                            <a href="{{ route('customers.show', $penjualan->customer) }}" class="text-primary-700 hover:underline">{{ $penjualan->customer?->name }}</a>
                        @else
                            <span class="text-slate-900">{{ $penjualan->customer?->name }}</span>
                        @endcan
                    </dd>
                </div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Metode pembayaran</dt><dd class="text-right text-slate-900">{{ $penjualan->payment_method->label() }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Kasir</dt><dd class="text-right text-slate-900">{{ $penjualan->user?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Gudang</dt><dd class="text-right text-slate-900">{{ $penjualan->warehouse?->name ?? '—' }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Sesi kasir</dt>
                    <dd class="text-right">
                        @if ($penjualan->cashSession && $user->can('view', $penjualan->cashSession))
                            <a href="{{ route('pos.sessions.show', $penjualan->cashSession) }}" class="text-primary-700 hover:underline">{{ $penjualan->cashSession->number }}</a>
                        @else
                            <span class="text-slate-900">{{ $penjualan->cashSession?->number ?? 'Tanpa sesi' }}</span>
                        @endif
                    </dd>
                </div>
                @if ($penjualan->note)
                    <div><dt class="text-slate-500">Catatan</dt><dd class="mt-1 text-slate-900">{{ $penjualan->note }}</dd></div>
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
                    @if ($lihatSemua)
                        <x-ui.table.th align="right">Laba</x-ui.table.th>
                    @endif
                </x-slot:head>
                @foreach ($penjualan->items as $item)
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
                        @if ($lihatSemua)
                            <x-ui.table.td align="right" numeric class="text-success-700">@rupiah($item->line_total - $item->cost_price * $item->quantity)</x-ui.table.td>
                        @endif
                    </tr>
                @endforeach
                <x-slot:footer>
                    <dl class="ml-auto grid w-full max-w-sm gap-2 text-sm">
                        <div class="{{ $baris }}"><dt class="text-slate-600">Subtotal</dt><dd class="tabular-nums">@rupiah($penjualan->subtotal)</dd></div>
                        @if ((float) $penjualan->item_discount > 0)
                            <div class="{{ $baris }}"><dt class="text-slate-600">Diskon item</dt><dd class="tabular-nums text-danger-600">− @rupiah($penjualan->item_discount)</dd></div>
                        @endif
                        @if ((float) $penjualan->discount > 0)
                            <div class="{{ $baris }}"><dt class="text-slate-600">Diskon transaksi</dt><dd class="tabular-nums text-danger-600">− @rupiah($penjualan->discount)</dd></div>
                        @endif
                        @if ((float) $penjualan->tax > 0)
                            <div class="{{ $baris }}">
                                <dt class="text-slate-600">Pajak {{ Format::jumlah($penjualan->tax_rate) }}%{{ $penjualan->tax_inclusive ? ' (termasuk harga)' : '' }}</dt>
                                <dd class="tabular-nums {{ $penjualan->tax_inclusive ? 'text-slate-500' : '' }}">{{ $penjualan->tax_inclusive ? '' : '+ ' }}@rupiah($penjualan->tax)</dd>
                            </div>
                        @endif
                        <div class="{{ $baris }} border-t border-slate-200 pt-2 text-base font-semibold text-slate-900"><dt>Total</dt><dd class="tabular-nums">@rupiah($penjualan->grand_total)</dd></div>
                        <div class="{{ $baris }}"><dt class="text-slate-600">Dibayar</dt><dd class="tabular-nums">@rupiah($penjualan->paid_amount)</dd></div>
                        @if ((float) $penjualan->change_amount > 0)
                            <div class="{{ $baris }}"><dt class="text-slate-600">Uang diterima · kembalian</dt><dd class="tabular-nums">@rupiah($penjualan->tendered_amount) · @rupiah($penjualan->change_amount)</dd></div>
                        @endif
                        @if ((float) $penjualan->returned_amount > 0)
                            <div class="{{ $baris }}"><dt class="text-slate-600">Retur</dt><dd class="tabular-nums text-warning-700">@rupiah($penjualan->returned_amount)</dd></div>
                        @endif
                        @if ($aktif && (float) $penjualan->balance > 0)
                            <div class="{{ $baris }} font-semibold text-warning-700"><dt>Sisa piutang</dt><dd class="tabular-nums">@rupiah($penjualan->balance)</dd></div>
                        @endif
                    </dl>
                </x-slot:footer>
            </x-ui.table>

            @if ($lihatSemua || $penjualan->user_id === $user->id)
                <x-ui.card title="Pembayaran" :padding="false">
                    @if ($penjualan->payments->isEmpty())
                        <x-ui.empty-state icon="banknotes" title="Belum ada pembayaran" description="Penjualan piutang tanpa bayar di muka. Pelunasan akan tampil di sini." />
                    @else
                        <x-ui.table :sticky="false">
                            <x-slot:head>
                                <x-ui.table.th>Nomor</x-ui.table.th>
                                <x-ui.table.th>Tanggal</x-ui.table.th>
                                <x-ui.table.th>Metode</x-ui.table.th>
                                <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                                <x-ui.table.th>Keterangan</x-ui.table.th>
                            </x-slot:head>
                            @foreach ($penjualan->payments as $bayar)
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
            @endif

            @if ($penjualan->returns->isNotEmpty())
                <x-ui.card title="Retur" :padding="false">
                    <x-ui.table :sticky="false">
                        <x-slot:head>
                            <x-ui.table.th>Nomor</x-ui.table.th>
                            <x-ui.table.th>Tanggal</x-ui.table.th>
                            <x-ui.table.th align="right">Nilai</x-ui.table.th>
                            <x-ui.table.th>Status</x-ui.table.th>
                        </x-slot:head>
                        @foreach ($penjualan->returns as $retur)
                            <tr>
                                <x-ui.table.td>
                                    @can('view', $retur)
                                        <a href="{{ route('sale-returns.show', $retur) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $retur->number }}</a>
                                    @else
                                        <span class="font-medium text-slate-900">{{ $retur->number }}</span>
                                    @endcan
                                </x-ui.table.td>
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
        <x-ui.void-dialog name="batal-penjualan" :action="route('sales.void', $penjualan)" :title="'Batalkan '.$penjualan->number.'?'">
            Stok seluruh barang kembali ke {{ $penjualan->warehouse?->name ?? 'gudang penjualan' }}, pembayarannya ditandai batal, dan piutang
            pelanggan (bila ada) dikoreksi. Dokumen tidak dihapus. Bila sudah ada pelunasan piutang atau retur, pembatalan ditolak;
            gunakan Retur Penjualan.
        </x-ui.void-dialog>
    @endif

    @if ($bisaLunasi)
        <div x-data="{ target: {{ \Illuminate\Support\Js::from($target) }} }">
            @include('partials.terima-piutang', ['methods' => $metode])
        </div>
    @endif
</x-app-layout>
