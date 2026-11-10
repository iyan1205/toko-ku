{{--
    Retur barang dari satu faktur. Isi jumlah per baris (maksimal sisa yang belum diretur).
    Nilai retur = jumlah x harga bersih per unit di faktur.
--}}
@use('App\Support\Format')
@php
    $bolehSetujui = auth()->user()->can('purchasing.returns.approve');
@endphp

<x-app-layout>
    <x-slot name="header">Retur dari {{ $faktur->number }}</x-slot>
    <x-slot name="description">{{ $faktur->supplier->name }} · {{ Format::tanggal($faktur->purchase_date) }} · sisa hutang {{ Format::rupiah($faktur->balance) }}</x-slot>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-6" title="Retur belum bisa disimpan">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('purchase-returns.store') }}" x-data="{ kirim: false }" x-on:submit="kirim = true" class="grid gap-6">
        @csrf
        <input type="hidden" name="purchase_id" value="{{ $faktur->id }}">
        <input type="hidden" name="aksi" value="draft" x-ref="aksi">

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="warehouse_id" label="Barang keluar dari gudang" required :options="$gudang->pluck('name', 'id')" :selected="$faktur->warehouse_id" />
                <x-ui.input name="return_date" label="Tanggal retur" type="date" :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" required />
                <x-ui.input name="reason" label="Alasan retur" maxlength="255" required placeholder="Misal: barang rusak saat diterima" />
            </div>
        </x-ui.card>

        <x-ui.table :sticky="false">
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Dibeli</x-ui.table.th>
                <x-ui.table.th align="right">Sudah diretur</x-ui.table.th>
                <x-ui.table.th align="right">Harga bersih</x-ui.table.th>
                <x-ui.table.th class="w-44">Diretur sekarang</x-ui.table.th>
            </x-slot:head>
            @foreach ($faktur->items as $item)
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
                    <x-ui.table.td align="right" numeric muted>@rupiah($item->netUnitPrice())</x-ui.table.td>
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
                    <p class="text-sm text-slate-500">Bila nilai retur melebihi sisa hutang, kelebihannya dicatat sebagai uang yang harus dikembalikan supplier.</p>
                    <div class="flex flex-wrap gap-2">
                        <x-ui.button :href="route('purchases.show', $faktur)" variant="secondary">Batal</x-ui.button>
                        <x-ui.button type="submit" variant="secondary" icon="document" x-on:click="$refs.aksi.value = 'draft'" x-bind:disabled="kirim">Simpan draft</x-ui.button>
                        @if ($bolehSetujui)
                            <x-ui.button type="submit" icon="check" x-on:click="$refs.aksi.value = 'approve'" x-bind:disabled="kirim">Simpan &amp; setujui</x-ui.button>
                        @endif
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.table>
    </form>
</x-app-layout>
