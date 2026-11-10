{{-- Detail stok opname. Draft: bisa diubah, diselesaikan, atau dihapus. Selesai: hanya dibaca. --}}
@use('App\Support\Format')
@php
    $draft = $opname->isDraft();
    $berselisih = $opname->items->filter(fn ($i) => (float) $i->difference != 0.0);
    $bergeser = $opname->items->filter(fn ($i) => $draft && abs(($stokKini[$i->product_id] ?? 0.0) - (float) $i->system_stock) > 0.0005);
    $tanpaAlasan = $berselisih->filter(fn ($i) => blank($i->reason));
@endphp

<x-app-layout>
    <x-slot name="header">Stok opname {{ $opname->number }}</x-slot>
    <x-slot name="description">{{ $opname->warehouse->name }} · {{ Format::tanggal($opname->opname_date) }}</x-slot>
    @if ($draft)
        <x-slot name="actions">
            @can('inventory.opnames.create')
                <x-ui.button variant="ghost" icon="trash" class="text-danger-600 hover:text-danger-700"
                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('stock-opnames.destroy', $opname)) }}, nama: {{ \Illuminate\Support\Js::from('Draft '.$opname->number) }}, pesan: 'akan dihapus. Stok tidak berubah.' })">
                    Hapus draft
                </x-ui.button>
                <x-ui.button variant="secondary" icon="pencil-square" :href="route('stock-opnames.edit', $opname)">Ubah hitungan</x-ui.button>
            @endcan
            @can('inventory.opnames.approve')
                <form method="POST" action="{{ route('stock-opnames.finalize', $opname) }}" x-data="{ kirim: false }" x-on:submit="kirim = true">
                    @csrf
                    <x-ui.button type="submit" icon="check" x-bind:disabled="kirim">Selesaikan opname</x-ui.button>
                </form>
            @endcan
        </x-slot>
    @endif

    @if ($draft)
        <x-ui.alert class="mb-6" title="Draft: stok belum berubah">
            Selisih {{ $berselisih->count() }} produk akan dibukukan ke stok {{ $opname->warehouse->name }} saat opname diselesaikan.
            @if ($bergeser->isNotEmpty())
                <span class="mt-1 block font-medium text-warning-800">Stok {{ $bergeser->count() }} produk berubah sejak dihitung. Ubah hitungan dan hitung ulang produk tersebut sebelum menyelesaikan.</span>
            @endif
            @if ($tanpaAlasan->isNotEmpty())
                <span class="mt-1 block font-medium text-warning-800">Alasan selisih belum diisi untuk {{ $tanpaAlasan->count() }} produk.</span>
            @endif
        </x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Rincian">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$opname->status->color()" dot>{{ $opname->status->label() }}</x-ui.badge></dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Gudang</dt><dd class="text-right text-slate-900">{{ $opname->warehouse->name }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Tanggal</dt><dd class="text-slate-900">{{ Format::tanggal($opname->opname_date) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Dihitung oleh</dt><dd class="text-right text-slate-900">{{ $opname->user?->name }}</dd></div>
                @unless ($draft)
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Diselesaikan</dt><dd class="text-right text-slate-900">{{ $opname->finalizedBy?->name }}<br><span class="text-xs text-slate-500">{{ Format::tanggal($opname->finalized_at, true) }}</span></dd></div>
                @endunless
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Produk dihitung</dt><dd class="tabular-nums text-slate-900">{{ $opname->items->count() }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Berselisih</dt><dd class="tabular-nums text-slate-900">{{ $berselisih->count() }}</dd></div>
                @if ($opname->note)
                    <div><dt class="text-slate-500">Catatan</dt><dd class="mt-1 text-slate-900">{{ $opname->note }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Stok sistem</x-ui.table.th>
                <x-ui.table.th align="right">Stok fisik</x-ui.table.th>
                <x-ui.table.th align="right">Selisih</x-ui.table.th>
                <x-ui.table.th>Alasan</x-ui.table.th>
            </x-slot:head>
            @foreach ($opname->items as $item)
                @php
                    $selisih = (float) $item->difference;
                @endphp
                <tr>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $item->product?->name }}</p>
                        <p class="text-xs text-slate-500">{{ $item->product?->sku }}</p>
                        @if ($bergeser->has($item->getKey()))
                            <p class="mt-1 text-xs font-medium text-warning-700">Stok kini {{ Format::jumlah($stokKini[$item->product_id] ?? 0) }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>{{ Format::jumlah($item->system_stock) }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->physical_stock) }} <span class="text-slate-500">{{ $item->product?->unit?->name }}</span></x-ui.table.td>
                    <x-ui.table.td align="right" numeric>
                        @if ($selisih == 0.0)
                            <span class="text-success-700">Cocok</span>
                        @else
                            <span @class(['font-semibold', 'text-success-700' => $selisih > 0, 'text-danger-600' => $selisih < 0])>{{ $selisih > 0 ? '+' : '−' }}{{ Format::jumlah(abs($selisih)) }}</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td muted>{{ $item->reason ?: '—' }}</x-ui.table.td>
                </tr>
            @endforeach
        </x-ui.table>
    </div>
</x-app-layout>
