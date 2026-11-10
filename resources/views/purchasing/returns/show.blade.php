{{-- Detail retur pembelian. Draft: setujui atau hapus. Disetujui: hanya dibaca. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Retur {{ $retur->number }}</x-slot>
    <x-slot name="description">{{ $retur->supplier->name }} · faktur {{ $retur->purchase->number }} · {{ Format::tanggal($retur->return_date) }}</x-slot>
    @if ($retur->isDraft())
        <x-slot name="actions">
            @can('purchasing.returns.delete')
                <x-ui.button variant="ghost" icon="trash" class="text-danger-600 hover:text-danger-700"
                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('purchase-returns.destroy', $retur)) }}, nama: {{ \Illuminate\Support\Js::from('Draft '.$retur->number) }} })">Hapus draft</x-ui.button>
            @endcan
            @can('purchasing.returns.approve')
                <form method="POST" action="{{ route('purchase-returns.approve', $retur) }}" x-data="{ kirim: false }" x-on:submit="kirim = true">
                    @csrf
                    <x-ui.button type="submit" icon="check" x-bind:disabled="kirim">Setujui retur</x-ui.button>
                </form>
            @endcan
        </x-slot>
    @endif

    @if ($retur->isDraft())
        <x-ui.alert class="mb-6" title="Draft: stok dan hutang belum berubah">
            Saat disetujui, barang keluar dari {{ $retur->warehouse->name }} dan hutang faktur {{ $retur->purchase->number }} berkurang {{ Format::rupiah($retur->total) }}.
        </x-ui.alert>
    @elseif ((float) $retur->refund_amount > 0)
        <x-ui.alert type="warning" class="mb-6" title="Supplier perlu mengembalikan uang">
            Faktur ini sudah dibayar, sehingga {{ Format::rupiah($retur->refund_amount) }} dari nilai retur harus dikembalikan supplier.
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$retur->status->color()" dot>{{ $retur->status->label() }}</x-ui.badge></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Faktur</dt><dd><a href="{{ route('purchases.show', $retur->purchase) }}" class="text-primary-700 hover:underline">{{ $retur->purchase->number }}</a></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Supplier</dt><dd class="text-right text-slate-900">{{ $retur->supplier->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Dari gudang</dt><dd class="text-right text-slate-900">{{ $retur->warehouse->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Dibuat oleh</dt><dd class="text-right text-slate-900">{{ $retur->user?->name }}</dd></div>
                @if ($retur->approved_at)
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Disetujui</dt><dd class="text-right text-slate-900">{{ $retur->approvedBy?->name }}<br><span class="text-xs text-slate-500">{{ Format::tanggal($retur->approved_at, true) }}</span></dd></div>
                @endif
                <div><dt class="text-slate-500">Alasan</dt><dd class="mt-1 text-slate-900">{{ $retur->reason }}</dd></div>
                <div class="flex justify-between gap-4 border-t border-slate-100 pt-3"><dt class="font-medium text-slate-700">Nilai retur</dt><dd class="font-semibold tabular-nums text-slate-900">@rupiah($retur->total)</dd></div>
                @if ((float) $retur->refund_amount > 0)
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Refund dari supplier</dt><dd class="tabular-nums text-warning-700">@rupiah($retur->refund_amount)</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Jumlah</x-ui.table.th>
                <x-ui.table.th align="right">Harga bersih</x-ui.table.th>
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
        </x-ui.table>
    </div>
</x-app-layout>
