<x-app-layout>
    <x-slot name="header">Riwayat mutasi stok</x-slot>

    <div class="rounded-lg border border-stone-200 bg-white">
        <div class="space-y-3 border-b border-stone-200 p-4">
            <form method="GET" action="{{ route('stock.movements') }}" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                @if ($produkDipilih)
                    <input type="hidden" name="produk" value="{{ $produkDipilih->id }}">
                @endif
                <select name="jenis" aria-label="Filter jenis" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <option value="">Semua jenis</option>
                    @foreach ($jenis as $j)
                        <option value="{{ $j->value }}" @selected(request('jenis') === $j->value)>{{ $j->label() }}</option>
                    @endforeach
                </select>
                <input type="date" name="dari" value="{{ request('dari') }}" aria-label="Dari tanggal"
                       class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                <input type="date" name="sampai" value="{{ request('sampai') }}" aria-label="Sampai tanggal"
                       class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Terapkan</button>
            </form>
            @error('sampai') <p class="text-sm text-red-600">{{ $message }}</p> @enderror

            <div class="flex flex-wrap items-center gap-2">
                @if ($produkDipilih)
                    <span class="rounded-full bg-stone-100 px-3 py-1 text-sm text-stone-700">Produk: <strong>{{ $produkDipilih->name }}</strong></span>
                    <a href="{{ route('stock.movements') }}" class="text-sm text-stone-600 hover:underline">Tampilkan semua produk</a>
                    <a href="{{ route('stock.adjust.create', ['produk' => $produkDipilih->id]) }}" class="text-sm text-teal-700 hover:underline">Sesuaikan stok produk ini</a>
                @else
                    <a href="{{ route('stock.adjust.create') }}" class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Penyesuaian stok</a>
                @endif
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-left text-stone-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">Waktu</th>
                        <th class="px-4 py-3 font-medium">Produk</th>
                        <th class="px-4 py-3 font-medium">Jenis</th>
                        <th class="px-4 py-3 text-right font-medium">Jumlah</th>
                        <th class="px-4 py-3 text-right font-medium">Stok sebelum → sesudah</th>
                        <th class="px-4 py-3 font-medium">Keterangan</th>
                        <th class="px-4 py-3 font-medium">Oleh</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($mutasi as $m)
                        <tr>
                            <td class="whitespace-nowrap px-4 py-3 text-stone-600">{{ \App\Support\Format::tanggal($m->created_at, true) }}</td>
                            <td class="px-4 py-3">
                                <a href="{{ route('products.show', $m->product_id) }}" class="font-medium text-stone-900 hover:underline">{{ $m->product?->name }}</a>
                                <p class="text-xs text-stone-500">{{ $m->product?->sku }}</p>
                            </td>
                            <td class="px-4 py-3">@include('partials.badge-mutasi', ['jenis' => $m->type])</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium {{ (float) $m->quantity > 0 ? 'text-teal-700' : 'text-red-700' }}">
                                {{ (float) $m->quantity > 0 ? '+' : '' }}{{ \App\Support\Format::jumlah($m->quantity) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-stone-600">
                                {{ \App\Support\Format::jumlah($m->stock_before) }} → <span class="font-medium text-stone-900">{{ \App\Support\Format::jumlah($m->stock_after) }}</span>
                            </td>
                            <td class="px-4 py-3 text-stone-600">
                                {{ $m->note ?: '-' }}
                                @if ($m->reference_type === 'stock_opname' && $m->reference)
                                    <a href="{{ route('stock-opnames.show', $m->reference) }}" class="ml-1 text-teal-700 hover:underline">{{ $m->reference->number }}</a>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $m->user?->name ?? 'Sistem' }}</td>
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
