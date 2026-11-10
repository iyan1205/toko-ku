{{--
    Retur barang dari satu transaksi kasir. Isi jumlah per baris (maksimal sisa yang belum diretur).
    Nilai retur = bagian baris dari total yang dibayar (diskon transaksi dan pajak ikut terbagi).
--}}
@use('App\Support\Format')
@php
    $bolehSetujui = auth()->user()->can('pos.returns.approve');
@endphp

<x-app-layout>
    <x-slot name="header">Retur dari {{ $penjualan->number }}</x-slot>
    <x-slot name="description">{{ $penjualan->customer?->name }} · {{ Format::tanggal($penjualan->sold_at, true) }} · total {{ Format::rupiah($penjualan->grand_total) }}</x-slot>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-6" title="Retur belum bisa disimpan">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('sale-returns.store') }}" x-data="{ kirim: false }" x-on:submit="kirim = true" class="grid gap-6">
        @csrf
        <input type="hidden" name="sale_id" value="{{ $penjualan->id }}">
        <input type="hidden" name="aksi" value="draft" x-ref="aksi">

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-ui.input name="reason" label="Alasan retur" maxlength="255" required placeholder="Misal: produk rusak, salah pesanan" />
                </div>
                @if ($bolehSetujui)
                    <x-ui.select name="refund_method" label="Uang dikembalikan lewat" :options="collect($methods)->mapWithKeys(fn ($m) => [$m->value => $m->label()])" :selected="old('refund_method', 'cash')"
                                 hint="Dipakai bila langsung disetujui dan ada uang yang dikembalikan." />
                @endif
            </div>
            <p class="mt-4 text-sm text-slate-500">Barang masuk kembali ke {{ $penjualan->warehouse?->name ?? 'gudang penjualan' }}. Bila transaksi masih punya piutang, nilai retur mengurangi piutang dulu; sisanya dikembalikan ke pelanggan.</p>
        </x-ui.card>

        <x-ui.table :sticky="false">
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Dibeli</x-ui.table.th>
                <x-ui.table.th align="right">Sudah diretur</x-ui.table.th>
                <x-ui.table.th align="right">Subtotal baris</x-ui.table.th>
                <x-ui.table.th class="w-44">Diretur sekarang</x-ui.table.th>
            </x-slot:head>
            @foreach ($penjualan->items as $item)
                @php
                    $sisa = $item->returnableQuantity();
                @endphp
                <tr @class(['text-slate-400' => $sisa <= 0])>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                        <p class="text-xs text-slate-500">{{ $item->sku }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>{{ Format::jumlah($item->returned_quantity) }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>@rupiah($item->line_total)</x-ui.table.td>
                    <x-ui.table.td>
                        @if ($sisa > 0)
                            <input type="number" min="0" max="{{ $sisa }}" step="0.001" name="items[{{ $item->id }}]" value="{{ old('items.'.$item->id) }}"
                                   placeholder="0" aria-label="Jumlah {{ $item->product_name }} diretur"
                                   class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <p class="mt-1 text-xs text-slate-500">maks {{ Format::jumlah($sisa) }}</p>
                            @error('items.'.$item->id) <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        @else
                            <span class="text-xs">Sudah diretur semua</span>
                        @endif
                    </x-ui.table.td>
                </tr>
            @endforeach
            <x-slot:footer>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm text-slate-500">{{ $bolehSetujui ? 'Simpan & setujui bila barang sudah diterima kembali.' : 'Retur Anda akan disetujui atasan.' }}</p>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button :href="route('sales.show', $penjualan)" variant="secondary">Batal</x-ui.button>
                        <x-ui.button type="submit" :variant="$bolehSetujui ? 'secondary' : 'primary'" icon="paper-airplane" x-on:click="$refs.aksi.value = 'draft'" x-bind:disabled="kirim">Ajukan retur</x-ui.button>
                        @if ($bolehSetujui)
                            <x-ui.button type="submit" icon="check" x-on:click="$refs.aksi.value = 'approve'" x-bind:disabled="kirim">Simpan &amp; setujui</x-ui.button>
                        @endif
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.table>
    </form>
</x-app-layout>
