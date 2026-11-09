<x-app-layout>
    <x-slot name="header">Resep (Bill of Materials)</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm text-stone-500">Resep bahan untuk tiap produk jual. Harga bahan otomatis mengikuti harga beli di produk.</p>
        </div>
        @can('inventory.recipes.create')
            <a href="{{ route('recipes.create') }}" class="tombol-utama">+ Buat Resep</a>
        @endcan
    </div>

    <div class="mt-4 kartu overflow-hidden">
        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th class="text-right">Jumlah Bahan</th>
                        <th class="text-right">Biaya per Batch</th>
                        <th class="text-right">Jumlah Produksi</th>
                        <th class="text-right">Biaya / Porsi</th>
                        <th class="text-right">Harga Jual</th>
                        <th class="text-right">Margin / Porsi</th>
                        <th class="text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recipes as $r)
                        @php
                            $totalBiaya = app(\App\Services\RecipeService::class)->hitungBiaya($r);
                            $jmlProduksi = $r->items->first()->quantity > 0 ? 1 : 1; // default 1; diisi manual
                            $biayaPorsi = $jmlProduksi > 0 ? $totalBiaya : 0;
                            $margin = (float) $r->product->selling_price - $biayaPorsi;
                        @endphp
                        <tr>
                            <td class="font-medium text-stone-900">{{ $r->product?->name ?? 'Produk dihapus' }}</td>
                            <td class="text-right">{{ $r->items->count() }} bahan</td>
                            <td class="text-right angka-rata">@rupiah($totalBiaya)</td>
                            <td class="text-right text-stone-600">—</td>
                            <td class="text-right text-stone-600">—</td>
                            <td class="text-right angka-rata">@rupiah($r->product->selling_price ?? 0)</td>
                            <td class="text-right angka-rata {{ $margin >= 0 ? 'text-emerald-700' : 'text-brand-700' }}">@rupiah($margin)</td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <a href="{{ route('recipes.show', $r) }}" class="text-brand-700 hover:underline">Detail</a>
                                    @can('inventory.recipes.update')
                                        <a href="{{ route('recipes.edit', $r) }}" class="text-brand-700 hover:underline">Ubah</a>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-10 text-center text-stone-500">
                                Belum ada resep. Klik <a href="{{ route('recipes.create') }}" class="text-brand-700 hover:underline">Buat Resep</a> untuk membuat yang pertama.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
