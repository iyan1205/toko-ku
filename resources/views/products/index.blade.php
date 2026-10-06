<x-app-layout>
    <x-slot name="header">Produk</x-slot>

    @php($kelola = auth()->user()->can('manage-products'))

    <div class="rounded-lg border border-stone-200 bg-white">
        <div class="space-y-3 border-b border-stone-200 p-4">
            <form method="GET" action="{{ route('products.index') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, SKU, atau scan barcode"
                       class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600 lg:col-span-2" autofocus>
                <select name="kategori" aria-label="Filter kategori" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua kategori</option>
                    @foreach ($kategori as $k)
                        <option value="{{ $k->id }}" @selected((string) request('kategori') === (string) $k->id)>{{ $k->name }}</option>
                    @endforeach
                </select>
                <select name="stok" aria-label="Filter stok" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua stok</option>
                    <option value="aman" @selected(request('stok') === 'aman')>Aman</option>
                    <option value="menipis" @selected(request('stok') === 'menipis')>Menipis</option>
                    <option value="habis" @selected(request('stok') === 'habis')>Habis</option>
                </select>
                <div class="flex gap-2">
                    <select name="status" aria-label="Filter status" class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                        <option value="">Semua status</option>
                        <option value="aktif" @selected(request('status') === 'aktif')>Aktif</option>
                        <option value="nonaktif" @selected(request('status') === 'nonaktif')>Nonaktif</option>
                    </select>
                    <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Terapkan</button>
                </div>
            </form>

            @if ($kelola)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('products.create') }}"
                       class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">Tambah produk</a>
                    <a href="{{ route('products.import') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Impor Excel</a>
                    <a href="{{ route('products.export', request()->only(['cari', 'kategori', 'stok', 'status'])) }}"
                       class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Ekspor Excel</a>
                </div>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Produk</th>
                        <th class="px-4 py-3 font-medium">Kategori</th>
                        @if ($kelola)
                            <th class="px-4 py-3 text-right font-medium">Harga beli</th>
                        @endif
                        <th class="px-4 py-3 text-right font-medium">Harga jual</th>
                        <th class="px-4 py-3 text-right font-medium">Stok</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($produk as $p)
                        <tr @class(['bg-stone-50 text-stone-500' => ! $p->is_active])>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    @if ($p->photoUrl())
                                        <img src="{{ $p->photoUrl() }}" alt="" class="h-10 w-10 rounded-md object-cover" loading="lazy">
                                    @else
                                        <span class="flex h-10 w-10 items-center justify-center rounded-md bg-stone-100 text-lg" aria-hidden="true">📦</span>
                                    @endif
                                    <div>
                                        <a href="{{ route('products.show', $p) }}" class="font-medium text-stone-900 hover:underline">{{ $p->name }}</a>
                                        <p class="text-xs text-stone-500">{{ $p->sku }}@if ($p->barcode) · {{ $p->barcode }}@endif</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $p->category?->name }}</td>
                            @if ($kelola)
                                <td class="px-4 py-3 text-right text-stone-600">@rupiah($p->purchase_price)</td>
                            @endif
                            <td class="px-4 py-3 text-right text-stone-900">@rupiah($p->selling_price)</td>
                            <td class="px-4 py-3 text-right">
                                <span class="font-medium">{{ \App\Support\Format::jumlah($p->stock) }}</span>
                                <span class="text-stone-500">{{ $p->unit?->name }}</span>
                                <div class="mt-1">@include('partials.badge-stok', ['status' => $p->statusStok()])</div>
                            </td>
                            <td class="px-4 py-3">
                                @if ($p->is_active)
                                    <span class="rounded-full bg-teal-50 px-2.5 py-0.5 text-xs font-medium text-teal-800">Aktif</span>
                                @else
                                    <span class="rounded-full bg-stone-200 px-2.5 py-0.5 text-xs font-medium text-stone-700">Nonaktif</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('products.show', $p) }}" class="text-stone-600 hover:underline">Detail</a>
                                    @can('update', $p)
                                        <a href="{{ route('products.edit', $p) }}" class="text-teal-700 hover:underline">Ubah</a>
                                    @endcan
                                    @can('delete', $p)
                                        <button type="button" class="text-red-600 hover:underline"
                                                @click="$dispatch('konfirmasi-hapus', { action: @js(route('products.destroy', $p)), nama: @js($p->name) })">Hapus</button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $kelola ? 7 : 6 }}" class="px-4 py-10 text-center text-stone-500">
                                Tidak ada produk yang cocok dengan pencarian atau filter ini.
                            </td>
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
