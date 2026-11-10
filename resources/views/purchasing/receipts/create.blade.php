{{--
    Formulir penerimaan barang dari satu PO. Jumlah diterima diisi per baris (bawaan = sisa pesanan;
    kosongkan/0 bila belum datang). Saat disimpan: stok gudang bertambah dan satu faktur hutang dibuat.
--}}
@use('App\Support\Format')
@php
    $jatuhTempo = now()->addDays(30)->format('Y-m-d');
@endphp

<x-app-layout>
    <x-slot name="header">Terima barang {{ $order->number }}</x-slot>
    <x-slot name="description">{{ $order->supplier->name }} · dipesan {{ Format::tanggal($order->order_date) }}</x-slot>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-6" title="Penerimaan belum bisa disimpan">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('goods-receipts.store', $order) }}" x-data="{ kirim: false }" x-on:submit="kirim = true" class="grid gap-6">
        @csrf

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <x-ui.select name="warehouse_id" label="Masuk ke gudang" required :options="$gudang->pluck('name', 'id')" :selected="$order->warehouse_id" />
                <x-ui.input name="received_date" label="Tanggal terima" type="date" :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" required />
                <x-ui.input name="delivery_note_number" label="Nomor surat jalan" maxlength="50" placeholder="Opsional" />
                <x-ui.input name="supplier_invoice_number" label="Nomor faktur supplier" maxlength="50" placeholder="Opsional" />
                <x-ui.input name="due_date" label="Jatuh tempo hutang" type="date" :value="$jatuhTempo" required hint="Bawaan 30 hari dari hari ini." />
                <x-ui.input name="note" label="Catatan" maxlength="255" placeholder="Opsional, misal kondisi barang" />
            </div>
        </x-ui.card>

        <x-ui.table :sticky="false">
            <x-slot:head>
                <x-ui.table.th>Produk</x-ui.table.th>
                <x-ui.table.th align="right">Dipesan</x-ui.table.th>
                <x-ui.table.th align="right">Sudah diterima</x-ui.table.th>
                <x-ui.table.th align="right">Harga</x-ui.table.th>
                <x-ui.table.th class="w-44">Diterima sekarang</x-ui.table.th>
            </x-slot:head>
            @foreach ($order->items as $item)
                @php
                    $sisa = $item->remainingQuantity();
                @endphp
                <tr @class(['text-slate-400' => $sisa <= 0])>
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ $item->product_name }}</p>
                        <p class="text-xs text-slate-500">{{ $item->sku }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::jumlah($item->quantity) }} {{ $item->unit_name }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>{{ Format::jumlah($item->received_quantity) }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric muted>@rupiah($item->unit_price)</x-ui.table.td>
                    <x-ui.table.td>
                        @if ($sisa > 0)
                            <input type="number" min="0" max="{{ $sisa }}" step="0.001" name="items[{{ $item->id }}]"
                                   value="{{ old('items.'.$item->id, $sisa) }}" aria-label="Jumlah {{ $item->product_name }} diterima"
                                   class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            <p class="mt-1 text-xs text-slate-500">sisa {{ Format::jumlah($sisa) }} {{ $item->unit_name }}</p>
                            @error('items.'.$item->id) <p class="mt-1 text-xs text-danger-600">{{ $message }}</p> @enderror
                        @else
                            <span class="text-xs">Sudah diterima semua</span>
                        @endif
                    </x-ui.table.td>
                </tr>
            @endforeach
            <x-slot:footer>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <x-ui.checkbox name="update_cost" label="Perbarui harga beli produk dengan harga di PO ini" :checked="true" unchecked-value="0"
                                   hint="Memakai harga bersih per unit (setelah diskon baris)." />
                    <div class="flex gap-2">
                        <x-ui.button :href="route('purchase-orders.show', $order)" variant="secondary">Batal</x-ui.button>
                        <x-ui.button type="submit" icon="inbox-arrow-down" x-bind:disabled="kirim">Simpan penerimaan</x-ui.button>
                    </div>
                </div>
            </x-slot:footer>
        </x-ui.table>
    </form>
</x-app-layout>
