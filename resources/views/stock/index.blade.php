<x-app-layout>
    <x-slot name="header">Stok</x-slot>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="kartu p-4">
            <p class="text-sm text-stone-500">Produk aktif</p>
            <p class="mt-1 text-2xl font-semibold text-stone-900">{{ $ringkasan['total'] }}</p>
        </div>
        <a href="{{ route('stock.index', ['stok' => 'menipis']) }}" class="kartu p-4 hover:border-accent-400">
            <p class="text-sm text-stone-500">Stok menipis</p>
            <p class="mt-1 text-2xl font-semibold text-accent-700">{{ $ringkasan['menipis'] }}</p>
        </a>
        <a href="{{ route('stock.index', ['stok' => 'habis']) }}" class="kartu p-4 hover:border-brand-400">
            <p class="text-sm text-stone-500">Stok habis</p>
            <p class="mt-1 text-2xl font-semibold text-brand-700">{{ $ringkasan['habis'] }}</p>
        </a>
        @if ($kelola)
            <div class="kartu p-4">
                <p class="text-sm text-stone-500">Nilai persediaan (harga beli)</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">@rupiah($ringkasan['nilai'])</p>
            </div>
        @endif
    </div>

    <div class="kartu">
        <div class="space-y-3 border-b border-stone-200 p-4">
            <form method="GET" action="{{ route('stock.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, SKU, atau scan barcode"
                       class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600 lg:col-span-2">
                <select name="kategori" aria-label="Filter kategori" class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                    <option value="">Semua kategori</option>
                    @foreach ($kategori as $k)
                        <option value="{{ $k->id }}" @selected((string) request('kategori') === (string) $k->id)>{{ $k->name }}</option>
                    @endforeach
                </select>
                <div class="flex gap-2">
                    <select name="stok" aria-label="Filter stok" class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                        <option value="">Semua stok</option>
                        <option value="aman" @selected(request('stok') === 'aman')>Aman</option>
                        <option value="menipis" @selected(request('stok') === 'menipis')>Menipis</option>
                        <option value="habis" @selected(request('stok') === 'habis')>Habis</option>
                    </select>
                    <button type="submit" class="tombol-kedua">Terapkan</button>
                </div>
            </form>

            @if ($kelola)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('stock.adjust.create') }}"
                       class="tombol-utama">Penyesuaian stok</a>
                    <a href="{{ route('stock.movements') }}" class="tombol-kedua">Riwayat mutasi</a>
                    @can('stock-opname')
                        <a href="{{ route('stock-opnames.index') }}" class="tombol-kedua">Stok opname</a>
                    @endcan
                </div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th class="font-medium">Produk</th>
                        <th class="font-medium">Kategori</th>
                        <th class="text-right font-medium">Stok</th>
                        <th class="text-right font-medium">Minimum</th>
                        <th class="font-medium">Status</th>
                        @if ($kelola)
                            <th class="text-right font-medium">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($produk as $p)
                        <tr @class(['bg-stone-50 text-stone-500' => ! $p->is_active])>
                            <td >
                                <a href="{{ route('products.show', $p) }}" class="font-medium text-stone-900 hover:underline">{{ $p->name }}</a>
                                <p class="text-xs text-stone-500">{{ $p->sku }}</p>
                            </td>
                            <td class="text-stone-600">{{ $p->category?->name }}</td>
                            <td class="text-right">
                                <span class="font-medium">{{ \App\Support\Format::jumlah($p->stock) }}</span>
                                <span class="text-stone-500">{{ $p->unit?->name }}</span>
                            </td>
                            <td class="text-right text-stone-600">{{ \App\Support\Format::jumlah($p->min_stock) }}</td>
                            <td >@include('partials.badge-stok', ['status' => $p->statusStok()])</td>
                            @if ($kelola)
                                <td >
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('stock.movements', ['produk' => $p->id]) }}" class="text-stone-600 hover:underline">Riwayat</a>
                                        <a href="{{ route('stock.adjust.create', ['produk' => $p->id]) }}" class="text-brand-700 hover:underline">Sesuaikan</a>
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $kelola ? 6 : 5 }}" class="px-4 py-10 text-center text-stone-500">Tidak ada produk yang cocok dengan pencarian atau filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($produk->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $produk->links() }}</div>
        @endif
    </div>
</x-app-layout>
