{{-- Detail retur penjualan: barang, nilai, pengaruh ke piutang dan uang kembali, persetujuan. --}}
@use('App\Support\Format')
@php
    $user = auth()->user();
    $bisaSetujui = $user->can('approve', $retur);
    $bisaHapus = $user->can('delete', $retur);
    $baris = 'flex justify-between gap-4';
@endphp

<x-app-layout>
    <x-slot name="header">Retur {{ $retur->number }}</x-slot>
    <x-slot name="description">{{ $retur->customer?->name }} · dari {{ $retur->sale?->number }} · {{ Format::tanggal($retur->return_date) }}</x-slot>
    <x-slot name="actions">
        @if ($bisaHapus)
            <x-ui.button variant="ghost" icon="trash" class="text-danger-600"
                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('sale-returns.destroy', $retur)) }}, nama: {{ \Illuminate\Support\Js::from('pengajuan retur '.$retur->number) }} })">Hapus</x-ui.button>
        @endif
        @if ($bisaSetujui)
            <x-ui.button icon="check" x-data x-on:click="$dispatch('open-modal', 'setujui-retur')">Setujui retur</x-ui.button>
        @endif
    </x-slot>

    @if ($retur->isDraft())
        <x-ui.alert type="warning" class="mb-6" title="Menunggu persetujuan">
            Stok dan uang belum berubah. {{ $bisaSetujui ? 'Setujui setelah barang diterima kembali.' : 'Retur ini perlu disetujui atasan (izin Setujui retur penjualan).' }}
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="{{ $baris }}"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$retur->status->color()" dot>{{ $retur->status->label() }}</x-ui.badge></dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Penjualan</dt>
                    <dd class="text-right">
                        @can('view', $retur->sale)
                            <a href="{{ route('sales.show', $retur->sale) }}" class="text-primary-700 hover:underline">{{ $retur->sale?->number }}</a>
                        @else
                            {{ $retur->sale?->number }}
                        @endcan
                    </dd>
                </div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Pelanggan</dt><dd class="text-right text-slate-900">{{ $retur->customer?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Stok kembali ke</dt><dd class="text-right text-slate-900">{{ $retur->warehouse?->name }}</dd></div>
                <div class="{{ $baris }}"><dt class="text-slate-500">Diajukan oleh</dt><dd class="text-right text-slate-900">{{ $retur->user?->name }}</dd></div>
                @if ($retur->approved_at)
                    <div class="{{ $baris }}"><dt class="text-slate-500">Disetujui</dt><dd class="text-right text-slate-900">{{ $retur->approvedBy?->name }}<br><span class="text-xs text-slate-500">{{ Format::tanggal($retur->approved_at, true) }}</span></dd></div>
                @endif
                <div><dt class="text-slate-500">Alasan</dt><dd class="mt-1 text-slate-900">{{ $retur->reason }}</dd></div>
            </dl>
        </x-ui.card>

        <div class="grid gap-6 lg:col-span-2">
            <x-ui.table :sticky="false">
                <x-slot:head>
                    <x-ui.table.th>Produk</x-ui.table.th>
                    <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                    <x-ui.table.th align="right">Nilai per unit</x-ui.table.th>
                    <x-ui.table.th align="right">Nilai</x-ui.table.th>
                </x-slot:head>
                @foreach ($retur->items as $item)
                    <tr>
                        <x-ui.table.td>
                            <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                            <p class="text-xs text-slate-500">{{ $item->sku }}</p>
                        </x-ui.table.td>
                        <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}</x-ui.table.td>
                        <x-ui.table.td align="right" numeric muted>@rupiah($item->unit_price)</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($item->line_total)</x-ui.table.td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <dl class="ml-auto grid w-full max-w-sm gap-2 text-sm">
                        <div class="{{ $baris }} text-base font-semibold text-slate-900"><dt>Nilai retur</dt><dd class="tabular-nums">@rupiah($retur->total)</dd></div>
                        @if (! $retur->isDraft())
                            <div class="{{ $baris }}"><dt class="text-slate-600">Mengurangi piutang</dt><dd class="tabular-nums">@rupiah($retur->receivable_reduction)</dd></div>
                            <div class="{{ $baris }} font-semibold"><dt class="text-slate-700">Uang dikembalikan</dt>
                                <dd class="tabular-nums">@rupiah($retur->refund_amount){{ $retur->refund_method ? ' · '.$retur->refund_method->label() : '' }}</dd></div>
                        @endif
                    </dl>
                </x-slot:footer>
            </x-ui.table>
        </div>
    </div>

    @if ($bisaSetujui)
        <x-ui.modal name="setujui-retur" title="Setujui retur {{ $retur->number }}?" :show="$errors->has('refund_method')">
            <form id="form-setujui-retur" method="POST" action="{{ route('sale-returns.approve', $retur) }}" class="grid gap-4">
                @csrf
                <p class="text-sm text-slate-600">
                    Stok barang masuk kembali ke {{ $retur->warehouse?->name }}.
                    @if ($estimatedRefund > 0)
                        Uang yang dikembalikan ke pelanggan sekitar <strong class="text-slate-900">{{ Format::rupiah($estimatedRefund) }}</strong>{{ (float) $retur->sale->balance > 0 ? ', setelah mengurangi sisa piutang '.Format::rupiah($retur->sale->balance) : '' }}.
                    @else
                        Nilai retur seluruhnya mengurangi sisa piutang transaksi; tidak ada uang yang dikembalikan.
                    @endif
                </p>
                @if ($estimatedRefund > 0)
                    <x-ui.select name="refund_method" label="Uang dikembalikan lewat" required :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])" :selected="old('refund_method', 'cash')"
                                 hint="Pengembalian tunai saat sesi kasir Anda terbuka mengurangi tunai di laci sesi itu." />
                @endif
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                <x-ui.button type="submit" form="form-setujui-retur" icon="check">Setujui</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</x-app-layout>
