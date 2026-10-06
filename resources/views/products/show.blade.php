<x-app-layout>
    <x-slot name="header">Detail produk</x-slot>

    <div class="max-w-4xl space-y-6">
        <div class="rounded-lg border border-stone-200 bg-white p-6">
            <div class="flex flex-col gap-5 sm:flex-row">
                @if ($produk->photoUrl())
                    <img src="{{ $produk->photoUrl() }}" alt="Foto {{ $produk->name }}" class="h-40 w-40 rounded-lg object-cover">
                @else
                    <div class="flex h-40 w-40 items-center justify-center rounded-lg bg-stone-100 text-5xl" aria-hidden="true">📦</div>
                @endif

                <div class="flex-1">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h2 class="text-xl font-semibold text-stone-900">{{ $produk->name }}</h2>
                            <p class="mt-1 text-sm text-stone-500">{{ $produk->category?->name }}</p>
                        </div>
                        <div class="flex gap-2">
                            @can('update', $produk)
                                <a href="{{ route('products.edit', $produk) }}"
                                   class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Ubah</a>
                            @endcan
                            <a href="{{ route('products.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Kembali</a>
                        </div>
                    </div>

                    <dl class="mt-5 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                        <div><dt class="text-stone-500">SKU</dt><dd class="font-medium text-stone-900">{{ $produk->sku }}</dd></div>
                        <div><dt class="text-stone-500">Barcode</dt><dd class="font-medium text-stone-900">{{ $produk->barcode ?: '-' }}</dd></div>
                        <div><dt class="text-stone-500">Satuan</dt><dd class="font-medium text-stone-900">{{ $produk->unit?->name }}</dd></div>
                        <div>
                            <dt class="text-stone-500">Status</dt>
                            <dd class="font-medium text-stone-900">{{ $produk->is_active ? 'Aktif' : 'Nonaktif' }}</dd>
                        </div>
                        <div><dt class="text-stone-500">Harga jual</dt><dd class="font-medium text-stone-900">@rupiah($produk->selling_price)</dd></div>
                        <div>
                            <dt class="text-stone-500">Diskon produk</dt>
                            <dd class="font-medium text-stone-900">
                                {{ \App\Support\Format::jumlah($produk->discount_percent) }}%
                                @if ((float) $produk->discount_percent > 0)
                                    <span class="font-normal text-stone-500">(menjadi @rupiah($produk->hargaSetelahDiskon()))</span>
                                @endif
                            </dd>
                        </div>

                        {{-- Harga beli dan laba hanya untuk yang berhak mengelola produk --}}
                        @can('update', $produk)
                            <div><dt class="text-stone-500">Harga beli</dt><dd class="font-medium text-stone-900">@rupiah($produk->purchase_price)</dd></div>
                            <div>
                                <dt class="text-stone-500">Laba kotor per {{ $produk->unit?->name }}</dt>
                                <dd class="font-medium text-stone-900">@rupiah((float) $produk->selling_price - (float) $produk->purchase_price)</dd>
                            </div>
                        @endcan
                    </dl>

                    @if ($produk->description)
                        <p class="mt-5 whitespace-pre-line text-sm text-stone-600">{{ $produk->description }}</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-stone-200 bg-white p-6">
            <h3 class="text-sm font-medium text-stone-600">Stok</h3>
            <div class="mt-3 flex flex-wrap items-end gap-x-8 gap-y-3">
                <p class="text-3xl font-semibold text-stone-900">
                    {{ \App\Support\Format::jumlah($produk->stock) }}
                    <span class="text-base font-normal text-stone-500">{{ $produk->unit?->name }}</span>
                </p>
                @include('partials.badge-stok', ['status' => $produk->statusStok()])
                <p class="text-sm text-stone-500">Minimum {{ \App\Support\Format::jumlah($produk->min_stock) }}</p>
            </div>
            @can('manage-stock')
                <div class="mt-6 border-t border-stone-100 pt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h4 class="text-sm font-medium text-stone-600">Mutasi terakhir</h4>
                        <div class="flex gap-3 text-sm">
                            <a href="{{ route('stock.movements', ['produk' => $produk->id]) }}" class="text-stone-600 hover:underline">Semua riwayat</a>
                            <a href="{{ route('stock.adjust.create', ['produk' => $produk->id]) }}" class="text-teal-700 hover:underline">Sesuaikan stok</a>
                        </div>
                    </div>
                    @forelse ($riwayat as $m)
                        <div class="flex items-center justify-between gap-3 border-b border-stone-100 py-2 text-sm last:border-0">
                            <div>
                                @include('partials.badge-mutasi', ['jenis' => $m->type])
                                <span class="ml-2 text-stone-500">@tanggal($m->created_at, true)</span>
                            </div>
                            <div class="text-right">
                                <span class="font-medium {{ (float) $m->quantity > 0 ? 'text-teal-700' : 'text-red-700' }}">{{ (float) $m->quantity > 0 ? '+' : '' }}{{ \App\Support\Format::jumlah($m->quantity) }}</span>
                                <span class="text-stone-500">→ {{ \App\Support\Format::jumlah($m->stock_after) }}</span>
                            </div>
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-stone-500">Belum ada mutasi stok untuk produk ini.</p>
                    @endforelse
                </div>
            @endcan
        </div>

        @can('delete', $produk)
            <div class="flex justify-end">
                <button type="button" class="text-sm text-red-600 hover:underline"
                        @click="$dispatch('konfirmasi-hapus', { action: @js(route('products.destroy', $produk)), nama: @js($produk->name) })">
                    Hapus produk ini
                </button>
            </div>
        @endcan
    </div>
</x-app-layout>
