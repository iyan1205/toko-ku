<x-app-layout>
    <x-slot name="header">Detail Resep</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <a href="{{ route('recipes.index') }}" class="text-sm text-stone-500 hover:text-brand-700 hover:underline">
            &larr; Kembali ke daftar
        </a>
        @can('inventory.recipes.update')
            <div class="flex gap-2">
                <a href="{{ route('recipes.edit', $recipe) }}" class="tombol-kedua">Ubah Resep</a>
                <button type="button"
                        @click="$dispatch('konfirmasi-hapus', { action: '{{ route('recipes.destroy', $recipe) }}', nama: 'Resep {{ $recipe->product?->name ?? '' }}' })"
                        class="tombol-bahaya">Hapus</button>
            </div>
        @endcan
    </div>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 class="text-xl font-semibold text-stone-900">{{ $recipe->product?->name ?? 'Produk dihapus' }}</h2>
            <p class="mt-1 text-sm text-stone-500">
                SKU: {{ $recipe->product?->sku ?? '-' }} &middot;
                Harga jual: <span class="font-medium text-stone-700">@rupiah($recipe->product->selling_price ?? 0)</span>
            </p>
        </div>
        <div class="text-right">
            <p class="text-xs uppercase tracking-wide text-stone-500">Total biaya bahan</p>
            <p class="text-2xl font-bold text-brand-700 angka-rata">@rupiah($totalBiaya)</p>
        </div>
    </div>

    @if ($recipe->notes)
        <p class="mt-3 rounded-lg bg-stone-50 px-4 py-3 text-sm text-stone-600">
            <span class="font-medium">Catatan:</span> {{ $recipe->notes }}
        </p>
    @endif

    <div class="mt-5 kartu overflow-hidden">
        <div class="kartu-kepala flex items-center justify-between">
            <h3 class="kartu-judul">Bahan yang dipakai</h3>
            <span class="text-xs text-stone-500">{{ $recipe->items->count() }} bahan</span>
        </div>
        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Bahan</th>
                        <th class="text-right">Jumlah</th>
                        <th>Satuan</th>
                        <th class="text-right">Harga Beli</th>
                        <th class="text-right">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recipe->items as $item)
                        @php
                            $bahan = $item->product;
                            $subtotal = $bahan ? (float) $bahan->purchase_price * (float) $item->quantity : 0;
                        @endphp
                        <tr>
                            <td class="font-medium text-stone-900">{{ $bahan?->name ?? 'Bahan dihapus' }}</td>
                            <td class="text-right angka-rata">{{ \App\Support\Format::jumlah($item->quantity) }}</td>
                            <td class="text-stone-600">{{ $item->unit?->name ?? $bahan?->unit?->name ?? '-' }}</td>
                            <td class="text-right angka-rata">@rupiah($bahan->purchase_price ?? 0)</td>
                            <td class="text-right font-medium text-stone-900 angka-rata">@rupiah($subtotal)</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-stone-500">
                                Belum ada bahan. <a href="{{ route('recipes.edit', $recipe) }}" class="text-brand-700 hover:underline">Tambahkan bahan</a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="font-semibold text-stone-900">Total Biaya Bahan</td>
                        <td class="text-right font-bold text-brand-700 angka-rata">@rupiah($totalBiaya)</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-3">
        <div class="kartu p-4">
            <p class="text-sm text-stone-500">Harga jual per porsi</p>
            <p class="mt-1 text-xl font-semibold text-stone-900 angka-rata">@rupiah($recipe->product->selling_price ?? 0)</p>
        </div>
        <div class="kartu p-4">
            <p class="text-sm text-stone-500">Biaya bahan per porsi</p>
            <p class="mt-1 text-xl font-semibold text-brand-700 angka-rata">@rupiah($totalBiaya)</p>
        </div>
        <div class="kartu p-4">
            <p class="text-sm text-stone-500">Margin per porsi</p>
            @php($margin = (float) ($recipe->product->selling_price ?? 0) - $totalBiaya)
            <p class="mt-1 text-xl font-semibold {{ $margin >= 0 ? 'text-emerald-700' : 'text-brand-700' }} angka-rata">@rupiah($margin)</p>
        </div>
    </div>

    <p class="mt-4 rounded-lg border border-accent-200 bg-accent-50 px-4 py-3 text-sm text-accent-900">
        Catatan: jumlah bahan dihitung per <strong>1 porsi</strong> produk jual.
        Jika satu produk jual membutuhkan 0,111 potong ayam, isi 0,111.
        Harga bahan otomatis mengikuti harga beli di menu Produk &rarr; Ubah.
    </p>
</x-app-layout>
