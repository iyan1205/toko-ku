{{-- Pilih produk dan jumlah label, ukuran kertas, lalu cetak dari browser. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Cetak Label</x-slot>
    <x-slot name="description">Label barcode dan harga. Barcode memakai kode barcode produk, atau SKU bila barcode kosong (Code 128, terbaca scanner kasir).</x-slot>

    <form method="GET" action="{{ route('product-labels.print') }}" target="_blank" class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.table :paginator="$products" :sticky="false">
                <x-slot:toolbar>
                    <div class="flex w-full items-center gap-2">
                        <input type="search" name="cari" value="{{ $filter['cari'] ?? '' }}" form="cari-label" placeholder="Cari nama, SKU, barcode" aria-label="Cari produk"
                               class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <x-ui.button type="submit" form="cari-label" variant="secondary" size="sm">Cari</x-ui.button>
                    </div>
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Produk</x-ui.table.th>
                    <x-ui.table.th>Kode</x-ui.table.th>
                    <x-ui.table.th align="right">Harga</x-ui.table.th>
                    <x-ui.table.th class="w-28">Jumlah label</x-ui.table.th>
                </x-slot:head>
                @forelse ($products as $p)
                    <tr>
                        <x-ui.table.td class="font-medium text-slate-900">{{ $p->name }}</x-ui.table.td>
                        <x-ui.table.td class="font-mono text-xs text-slate-600">{{ $p->barcode ?: $p->sku }}</x-ui.table.td>
                        <x-ui.table.td align="right" numeric>@rupiah($p->hargaSetelahDiskon())</x-ui.table.td>
                        <x-ui.table.td>
                            <input type="number" name="copies[{{ $p->id }}]" min="0" max="500" value="0" aria-label="Jumlah label {{ $p->name }}"
                                   class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        </x-ui.table.td>
                    </tr>
                @empty
                    <x-ui.table.empty colspan="4" icon="tag" title="Produk tidak ditemukan" />
                @endforelse
            </x-ui.table>
        </div>

        <x-ui.form-section title="Kertas & isi label" description="Isi jumlah pada produk yang ingin dicetak (halaman ini saja), lalu Cetak.">
            <x-ui.select name="size" label="Ukuran label" required :options="$sizes" />
            <x-ui.select name="layout" label="Kertas" required :options="['roll' => 'Printer label (gulungan, satu label per halaman)', 'a4' => 'Kertas stiker A4']" />
            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium text-slate-700">Tampilkan</legend>
                @foreach (['name' => 'Nama produk', 'price' => 'Harga jual', 'sku' => 'SKU', 'store' => 'Nama toko'] as $k => $label)
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="show[]" value="{{ $k }}" @checked(in_array($k, ['name', 'price'], true)) class="rounded border-slate-300 text-primary-600"> {{ $label }}
                    </label>
                @endforeach
            </fieldset>
            <x-slot:footer><x-ui.button type="submit" icon="printer">Cetak label</x-ui.button></x-slot:footer>
        </x-ui.form-section>
    </form>
    <form id="cari-label" method="GET" action="{{ route('product-labels.index') }}"></form>
</x-app-layout>
