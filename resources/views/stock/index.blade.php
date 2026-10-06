<x-app-layout>
    <x-slot name="header">Stok</x-slot>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-stone-200 bg-white p-4">
            <p class="text-sm text-stone-500">Produk aktif</p>
            <p class="mt-1 text-2xl font-semibold text-stone-900">{{ $ringkasan['total'] }}</p>
        </div>
        <a href="{{ route('stock.index', ['stok' => 'menipis']) }}" class="rounded-lg border border-stone-200 bg-white p-4 hover:border-amber-400">
            <p class="text-sm text-stone-500">Stok menipis</p>
            <p class="mt-1 text-2xl font-semibold text-amber-700">{{ $ringkasan['menipis'] }}</p>
        </a>
        <a href="{{ route('stock.index', ['stok' => 'habis']) }}" class="rounded-lg border border-stone-200 bg-white p-4 hover:border-red-400">
            <p class="text-sm text-stone-500">Stok habis</p>
            <p class="mt-1 text-2xl font-semibold text-red-700">{{ $ringkasan['habis'] }}</p>
        </a>
        @if ($kelola)
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <p class="text-sm text-stone-500">Nilai persediaan (harga beli)</p>
                <p class="mt-1 text-2xl font-semibold text-stone-900">@rupiah($ringkasan['nilai'])</p>
            </div>
        @endif
    </div>

    <div class="rounded-lg border border-stone-200 bg-white">
        <div class="space-y-3 border-b border-stone-200 p-4">
            <form method="GET" action="{{ route('stock.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, SKU, atau scan barcode"
                       class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600 lg:col-span-2">
                <select name="kategori" aria-label="Filter kategori" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua kategori</option>
                    @foreach ($kategori as $k)
                        <option value="{{ $k->id }}" @selected((string) request('kategori') === (string) $k->id)>{{ $k->name }}</option>
                    @endforeach
                </select>
                <div class="flex gap-2">
                    <select name="stok" aria-label="Filter stok" class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                        <option value="">Semua stok</option>
                        <option value="aman" @selected(request('stok') === 'aman')>Aman</option>
                        <option value="menipis" @selected(request('stok') === 'menipis')>Menipis</option>
                        <option value="habis" @selected(request('stok') === 'habis')>Habis</option>
                    </select>
                    <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Terapkan</button>
                </div>
            </form>

            @if ($kelola)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('stock.adjust.create') }}"
                       class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">Penyesuaian stok</a>
                    <a href="{{ route('stock.movements') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Riwayat mutasi</a>
                    @can('stock-opname')
                        <a href="{{ route('stock-opnames.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Stok opname</a>
                    @endcan
                </div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Produk</th>
                        <th class="px-4 py-3 font-medium">Kategori</th>
                        <th class="px-4 py-3 text-right font-medium">Stok</th>
                        <th class="px-4 py-3 text-right font-medium">Minimum</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        @if ($kelola)
                            <th class="px-4 py-3 text-right font-medium">Aksi</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($produk as $p)
                        <tr @class(['bg-stone-50 text-stone-500' => ! $p->is_active])>
                            <td class="px-4 py-3">
                                <a href="{{ route('products.show', $p) }}" class="font-medium text-stone-900 hover:underline">{{ $p->name }}</a>
                                <p class="text-xs text-stone-500">{{ $p->sku }}</p>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $p->category?->name }}</td>
                            <td class="px-4 py-3 text-right">
                                <span class="font-medium">{{ \App\Support\Format::jumlah($p->stock) }}</span>
                                <span class="text-stone-500">{{ $p->unit?->name }}</span>
                            </td>
                            <td class="px-4 py-3 text-right text-stone-600">{{ \App\Support\Format::jumlah($p->min_stock) }}</td>
                            <td class="px-4 py-3">@include('partials.badge-stok', ['status' => $p->statusStok()])</td>
                            @if ($kelola)
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-3">
                                        <a href="{{ route('stock.movements', ['produk' => $p->id]) }}" class="text-stone-600 hover:underline">Riwayat</a>
                                        <a href="{{ route('stock.adjust.create', ['produk' => $p->id]) }}" class="text-teal-700 hover:underline">Sesuaikan</a>
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
