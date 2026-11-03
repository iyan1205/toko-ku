<x-app-layout>
    <x-slot name="header">Detail stok opname</x-slot>

    @php($selisih = $opname->items->filter(fn ($i) => (float) $i->difference != 0.0))

    <div class="max-w-5xl space-y-6">
        <div class="kartu p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold text-stone-900">{{ $opname->number }}</h2>
                    <p class="mt-1 text-sm text-stone-500">Tanggal @tanggal($opname->opname_date) · oleh {{ $opname->user?->name }}</p>
                </div>
                <a href="{{ route('stock-opnames.index') }}" class="tombol-kedua">Kembali</a>
            </div>
            @if ($opname->note)
                <p class="mt-4 text-sm text-stone-600">{{ $opname->note }}</p>
            @endif
            <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                <div><dt class="text-stone-500">Produk dihitung</dt><dd class="text-lg font-semibold text-stone-900">{{ $opname->items->count() }}</dd></div>
                <div><dt class="text-stone-500">Produk cocok</dt><dd class="text-lg font-semibold text-brand-700">{{ $opname->items->count() - $selisih->count() }}</dd></div>
                <div><dt class="text-stone-500">Produk berselisih</dt><dd class="text-lg font-semibold {{ $selisih->count() ? 'text-accent-700' : 'text-stone-900' }}">{{ $selisih->count() }}</dd></div>
            </dl>
        </div>

        <div class="kartu">
            <div class="overflow-x-auto">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th class="font-medium">Produk</th>
                            <th class="text-right font-medium">Stok sistem</th>
                            <th class="text-right font-medium">Stok fisik</th>
                            <th class="text-right font-medium">Selisih</th>
                            <th class="font-medium">Alasan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($opname->items as $i)
                            <tr @class(['bg-accent-50' => (float) $i->difference != 0.0])>
                                <td >
                                    <a href="{{ route('stock.movements', ['produk' => $i->product_id]) }}" class="font-medium text-stone-900 hover:underline">{{ $i->product?->name }}</a>
                                    <p class="text-xs text-stone-500">{{ $i->product?->sku }}</p>
                                </td>
                                <td class="text-right text-stone-600">{{ \App\Support\Format::jumlah($i->system_stock) }}</td>
                                <td class="text-right text-stone-900">{{ \App\Support\Format::jumlah($i->physical_stock) }}</td>
                                <td class="px-4 py-3 text-right font-medium {{ (float) $i->difference > 0 ? 'text-emerald-700' : ((float) $i->difference < 0 ? 'text-brand-700' : 'text-stone-500') }}">
                                    @if ((float) $i->difference == 0.0) Cocok @else {{ (float) $i->difference > 0 ? '+' : '' }}{{ \App\Support\Format::jumlah($i->difference) }} @endif
                                </td>
                                <td class="text-stone-600">{{ $i->reason ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <p class="text-sm text-stone-500">Produk yang berselisih sudah dicatat sebagai mutasi "Penyesuaian Stok" yang merujuk nomor opname ini. Produk yang cocok tidak menghasilkan mutasi.</p>
    </div>
</x-app-layout>
