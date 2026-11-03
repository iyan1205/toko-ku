<x-app-layout>
    <x-slot name="header">Stok opname</x-slot>

    <div class="kartu">
        <div class="flex items-center justify-between gap-3 border-b border-stone-200 p-4">
            <p class="text-sm text-stone-600">Hitung stok fisik, bandingkan dengan stok sistem, dan selisihnya dicatat sebagai mutasi.</p>
            <a href="{{ route('stock-opnames.create') }}"
               class="whitespace-nowrap rounded-md bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-600 focus:ring-offset-2">Opname baru</a>
        </div>

        <div class="overflow-x-auto">
            <table class="tabel">
                <thead>
                    <tr>
                        <th class="font-medium">Nomor</th>
                        <th class="font-medium">Tanggal</th>
                        <th class="text-right font-medium">Produk dihitung</th>
                        <th class="text-right font-medium">Ada selisih</th>
                        <th class="font-medium">Petugas</th>
                        <th class="text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($opname as $o)
                        <tr>
                            <td class="font-medium text-stone-900">{{ $o->number }}</td>
                            <td class="text-stone-600">@tanggal($o->opname_date)</td>
                            <td class="text-right text-stone-600">{{ $o->items_count }}</td>
                            <td class="px-4 py-3 text-right {{ $o->selisih_count > 0 ? 'font-medium text-accent-700' : 'text-stone-600' }}">{{ $o->selisih_count }}</td>
                            <td class="text-stone-600">{{ $o->user?->name }}</td>
                            <td class="text-right"><a href="{{ route('stock-opnames.show', $o) }}" class="text-brand-700 hover:underline">Detail</a></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-stone-500">Belum ada stok opname. Klik "Opname baru" untuk memulai.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($opname->hasPages())
            <div class="border-t border-stone-200 p-4">{{ $opname->links() }}</div>
        @endif
    </div>
</x-app-layout>
