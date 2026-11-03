<x-app-layout>
    <x-slot name="header">Riwayat mutasi stok</x-slot>

    <div class="kartu">
        <div class="space-y-3 border-b border-stone-200 p-4">
            <form method="GET" action="{{ route('stock.movements') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                @if ($produkDipilih)
                    <input type="hidden" name="produk" value="{{ $produkDipilih->id }}">
                @endif
                <select name="jenis" aria-label="Filter jenis" class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                    <option value="">Semua jenis</option>
                    @foreach ($jenis as $j)
                        <option value="{{ $j->value }}" @selected(request('jenis') === $j->value)>{{ $j->label() }}</option>
                    @endforeach
                </select>
                <input type="date" name="dari" value="{{ request('dari') }}" aria-label="Dari tanggal"
                       class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                <input type="date" name="sampai" value="{{ request('sampai') }}" aria-label="Sampai tanggal"
                       class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                <button type="submit" class="tombol-kedua">Terapkan</button>
            </form>
            @error('sampai') <p class="text-sm text-brand-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-2">
                @if ($produkDipilih)
                    <span class="rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700">Produk: <strong>{{ $produkDipilih->name }}</strong></span>
                    <a href="{{ route('stock.movements') }}" class="text-sm text-stone-600 hover:underline">Tampilkan semua produk</a>
                    <a href="{{ route('stock.adjust.create', ['produk' => $produkDipilih->id]) }}" class="text-sm text-brand-700 hover:underline">Sesuaikan stok produk ini</a>
                @else
                    <a href="{{ route('stock.adjust.create') }}" class="tombol-utama">Penyesuaian stok</a>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th class="font-medium">Waktu</th>
                        <th class="font-medium">Produk</th>
                        <th class="font-medium">Jenis</th>
                        <th class="text-right font-medium">Jumlah</th>
                        <th class="text-right font-medium">Stok sebelum → sesudah</th>
                        <th class="font-medium">Keterangan</th>
                        <th class="font-medium">Oleh</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($mutasi as $m)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-stone-600">{{ \App\Support\Format::tanggal($m->created_at, true) }}</td>
                            <td >
                                <a href="{{ route('products.show', $m->product_id) }}" class="font-medium text-stone-900 hover:underline">{{ $m->product?->name }}</a>
                                <p class="text-xs text-stone-500">{{ $m->product?->sku }}</p>
                            </td>
                            <td >@include('partials.badge-mutasi', ['jenis' => $m->type])</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium {{ (float) $m->quantity > 0 ? 'text-emerald-700' : 'text-brand-700' }}">
                                {{ (float) $m->quantity > 0 ? '+' : '' }}{{ \App\Support\Format::jumlah($m->quantity) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-stone-600">
                                {{ \App\Support\Format::jumlah($m->stock_before) }} → <span class="font-medium text-stone-900">{{ \App\Support\Format::jumlah($m->stock_after) }}</span>
                            </td>
                            <td class="text-stone-600">
                                {{ $m->note ?: '-' }}
                                @if ($m->reference_type === 'stock_opname' && $m->reference)
                                    <a href="{{ route('stock-opnames.show', $m->reference) }}" class="ml-1 text-brand-700 hover:underline">{{ $m->reference->number }}</a>
                                @endif
                            </td>
                            <td class="text-stone-600">{{ $m->user?->name ?? 'Sistem' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-stone-500">Belum ada mutasi stok yang cocok dengan filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($mutasi->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $mutasi->links() }}</div>
        @endif
    </div>
</x-app-layout>
