{{--
    Posisi stok: produk × gudang. Tanpa filter gudang, setiap gudang aktif menjadi kolom
    ditambah total. Status stok memakai total semua gudang terhadap stok minimum produk.
--}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Stok</x-slot>
    <x-slot name="description">Posisi stok setiap produk per gudang. Klik angka untuk melihat kartu stoknya.</x-slot>
    <x-slot name="actions">
        @can('inventory.stock.export')
            <x-ui.export-menu :href="route('stock.export')" />
        @endcan
        @can('inventory.transfers.create')
            <x-ui.button variant="secondary" :href="route('stock-transfers.create')" icon="arrows-right-left">Transfer stok</x-ui.button>
        @endcan
        @if ($kelola)
            <x-ui.button :href="route('stock.adjust.create', array_filter(['gudang' => $gudangDipilih?->id]))" icon="adjustments-horizontal">Penyesuaian stok</x-ui.button>
        @endif
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card label="Produk aktif" :value="Format::jumlah($ringkasan['total'])" icon="cube" color="neutral" />
        <x-ui.stat-card label="Stok menipis" :value="Format::jumlah($ringkasan['menipis'])" icon="exclamation-triangle" color="warning"
                        :href="route('stock.index', ['stok' => 'menipis'])" />
        <x-ui.stat-card label="Stok habis" :value="Format::jumlah($ringkasan['habis'])" icon="x-circle" color="danger"
                        :href="route('stock.index', ['stok' => 'habis'])" />
        @if ($kelola)
            <x-ui.stat-card label="Nilai persediaan" :value="Format::rupiah($ringkasan['nilai'])" icon="banknotes"
                            :hint="$gudangDipilih ? 'di '.$gudangDipilih->name.', harga beli' : 'semua gudang, harga beli'" />
        @endif
    </div>

    <x-ui.table :paginator="$produk">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nama, SKU, atau scan barcode">
                <x-ui.select name="gudang" label="Gudang" hide-label :options="$gudang->mapWithKeys(fn ($g) => [$g->id => $g->name.($g->is_active ? '' : ' (nonaktif)')])"
                             :selected="request('gudang')" placeholder="Semua gudang" />
                <x-ui.select name="kategori" label="Kategori" hide-label :options="$kategori->pluck('name', 'id')" :selected="request('kategori')" placeholder="Semua kategori" />
                <x-ui.select name="stok" label="Status stok" hide-label :options="['aman' => 'Aman', 'menipis' => 'Menipis', 'habis' => 'Habis']" :selected="request('stok')" placeholder="Semua status" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="name">Produk</x-ui.table.th>
            <x-ui.table.th sort="category">Kategori</x-ui.table.th>
            @foreach ($gudangKolom as $g)
                <x-ui.table.th align="right" :sort="$gudangDipilih ? 'stock' : null">{{ $g->name }}</x-ui.table.th>
            @endforeach
            @unless ($gudangDipilih)
                <x-ui.table.th align="right" sort="stock">Total</x-ui.table.th>
            @endunless
            <x-ui.table.th>Status</x-ui.table.th>
            @if ($kelola)
                <x-ui.table.th align="right" sort="value">Nilai</x-ui.table.th>
            @endif
        </x-slot:head>

        @forelse ($produk as $p)
            <tr @class(['text-slate-500' => ! $p->is_active])>
                <x-ui.table.td>
                    <a href="{{ route('products.show', $p) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->name }}</a>
                    <p class="text-xs text-slate-500">{{ $p->sku }} · {{ $p->unit?->name }}@unless ($p->is_active) · nonaktif @endunless</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $p->category?->name }}</x-ui.table.td>
                @foreach ($gudangKolom as $g)
                    @php($qty = $p->stockIn($g))
                    <x-ui.table.td align="right" numeric>
                        <a href="{{ route('stock.movements', ['produk' => $p->id, 'gudang' => $g->id]) }}"
                           @class(['hover:underline', 'text-slate-400' => $qty == 0, 'font-medium text-slate-900' => $qty > 0, 'font-medium text-danger-600' => $qty < 0])>{{ Format::jumlah($qty) }}</a>
                    </x-ui.table.td>
                @endforeach
                @unless ($gudangDipilih)
                    <x-ui.table.td align="right" numeric>
                        <a href="{{ route('stock.movements', ['produk' => $p->id]) }}" class="font-semibold text-slate-900 hover:underline">{{ Format::jumlah($p->stock) }}</a>
                        <p class="text-xs text-slate-500">min {{ Format::jumlah($p->min_stock) }}</p>
                    </x-ui.table.td>
                @endunless
                <x-ui.table.td>@include('partials.badge-stok', ['status' => $p->statusStok()])</x-ui.table.td>
                @if ($kelola)
                    <x-ui.table.td align="right" numeric muted>@rupiah($list->value($p))</x-ui.table.td>
                @endif
            </tr>
        @empty
            <x-ui.table.empty :colspan="3 + $gudangKolom->count() + ($gudangDipilih ? 0 : 1) + ($kelola ? 1 : 0)" icon="cube" title="Tidak ada produk yang cocok" description="Ubah kata kunci atau filter pencarian." />
        @endforelse
    </x-ui.table>
</x-app-layout>
