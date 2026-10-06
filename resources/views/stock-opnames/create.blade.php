<x-app-layout>
    <x-slot name="header">Opname baru</x-slot>

    <div class="space-y-4">
        {{-- Filter hanya memilih produk yang ditampilkan; opname boleh per kategori atau per rak. --}}
        <form method="GET" action="{{ route('stock-opnames.create') }}" class="grid gap-2 rounded-lg border border-stone-200 bg-white p-4 sm:grid-cols-3">
            <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, SKU, atau barcode"
                   class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
            <select name="kategori" aria-label="Filter kategori" class="rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                <option value="">Semua kategori</option>
                @foreach ($kategori as $k)
                    <option value="{{ $k->id }}" @selected((string) request('kategori') === (string) $k->id)>{{ $k->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Tampilkan produk</button>
        </form>

        @if ($terpotong)
            <p class="rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                Hanya {{ $batas }} produk pertama yang ditampilkan. Gunakan filter kategori atau pencarian untuk opname per bagian.
            </p>
        @endif

        <form method="POST" action="{{ route('stock-opnames.store') }}" class="rounded-lg border border-stone-200 bg-white">
            @csrf

            <div class="grid gap-4 border-b border-stone-200 p-4 sm:grid-cols-2">
                <x-isian label="Tanggal opname" name="opname_date" type="date" :value="now()->format('Y-m-d')" :wajib="true" :max="now()->format('Y-m-d')" />
                <x-isian label="Catatan" name="note" maxlength="255" hint="Contoh: opname rak minuman, akhir bulan." />
            </div>

            @error('items') <p class="border-b border-stone-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</p> @enderror

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50 text-left text-stone-600">
                        <tr>
                            <th class="px-4 py-3 font-medium">Produk</th>
                            <th class="px-4 py-3 text-right font-medium">Stok sistem</th>
                            <th class="w-36 px-4 py-3 font-medium">Stok fisik</th>
                            <th class="px-4 py-3 text-right font-medium">Selisih</th>
                            <th class="px-4 py-3 font-medium">Alasan selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($produk as $p)
                            <tr x-data="{
                                    sistem: {{ (float) $p->stock }},
                                    fisik: @js(old('items.' . $p->id . '.physical_stock', '')),
                                    get selisih() { return this.fisik === '' || this.fisik === null ? null : Math.round((Number(this.fisik) - this.sistem) * 1000) / 1000 }
                                }"
                                :class="selisih !== null && selisih !== 0 ? 'bg-amber-50' : ''">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-stone-900">{{ $p->name }}</p>
                                    <p class="text-xs text-stone-500">{{ $p->sku }}</p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    {{ \App\Support\Format::jumlah($p->stock) }} <span class="text-stone-500">{{ $p->unit?->name }}</span>
                                    <input type="hidden" name="items[{{ $p->id }}][system_stock]" value="{{ (float) $p->stock }}">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0" step="0.001" x-model="fisik" name="items[{{ $p->id }}][physical_stock]"
                                           aria-label="Stok fisik {{ $p->name }}"
                                           class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                                    @error('items.' . $p->id . '.physical_stock') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-4 py-3 text-right font-medium">
                                    <span x-show="selisih === null" class="text-stone-400">-</span>
                                    <span x-show="selisih === 0" x-cloak class="text-teal-700">Cocok</span>
                                    <span x-show="selisih !== null && selisih !== 0" x-cloak
                                          :class="selisih > 0 ? 'text-teal-700' : 'text-red-700'"
                                          x-text="(selisih > 0 ? '+' : '') + selisih"></span>
                                </td>
                                <td class="px-4 py-3">
                                    <input type="text" maxlength="255" name="items[{{ $p->id }}][reason]" value="{{ old('items.' . $p->id . '.reason') }}"
                                           placeholder="Wajib bila ada selisih" aria-label="Alasan selisih {{ $p->name }}"
                                           :required="selisih !== null && selisih !== 0"
                                           class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                                    @error('items.' . $p->id . '.reason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-10 text-center text-stone-500">Tidak ada produk aktif yang cocok dengan filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-3 border-t border-stone-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-stone-500">Kosongkan stok fisik untuk produk yang tidak dihitung; produk itu dilewati.</p>
                <div class="flex gap-2">
                    <a href="{{ route('stock-opnames.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Batal</a>
                    <button type="submit"
                            class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">Simpan opname</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
