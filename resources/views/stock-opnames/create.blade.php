<x-app-layout>
    <x-slot name="header">Opname baru</x-slot>

    <div class="space-y-4">
        {{-- Filter hanya memilih produk yang ditampilkan; opname boleh per kategori atau per rak. --}}
        <form method="GET" action="{{ route('stock-opnames.create') }}" class="grid gap-2 rounded-lg border border-stone-200 bg-white p-4 sm:grid-cols-3">
            <input type="search" name="cari" value="{{ request('cari') }}" placeholder="Cari nama, SKU, atau barcode"
                   class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
            <select name="kategori" aria-label="Filter kategori" class="rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                <option value="">Semua kategori</option>
                @foreach ($kategori as $k)
                    <option value="{{ $k->id }}" @selected((string) request('kategori') === (string) $k->id)>{{ $k->name }}</option>
                @endforeach
            </select>
            <button type="submit" class="tombol-kedua">Tampilkan produk</button>
        </form>

        @if ($terpotong)
            <p class="rounded-md bg-accent-50 px-4 py-3 text-sm text-accent-800">
                Hanya {{ $batas }} produk pertama yang ditampilkan. Gunakan filter kategori atau pencarian untuk opname per bagian.
            </p>
        @endif

        <form method="POST" action="{{ route('stock-opnames.store') }}" class="kartu">
            @csrf

            <div class="grid gap-4 border-b border-stone-200 p-4 sm:grid-cols-2">
                <x-isian label="Tanggal opname" name="opname_date" type="date" :value="now()->format('Y-m-d')" :wajib="true" :max="now()->format('Y-m-d')" />
                <x-isian label="Catatan" name="note" maxlength="255" hint="Contoh: opname rak minuman, akhir bulan." />
            </div>

            @error('items') <p class="border-b border-stone-200 bg-brand-50 px-4 py-3 text-sm text-brand-700">{{ $message }}</p> @enderror

            <div class="overflow-x-auto">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th class="font-medium">Produk</th>
                            <th class="text-right font-medium">Stok sistem</th>
                            <th class="w-36 font-medium">Stok fisik</th>
                            <th class="text-right font-medium">Selisih</th>
                            <th class="font-medium">Alasan selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($produk as $p)
                            <tr x-data="{
                                    sistem: {{ (float) $p->stock }},
                                    fisik: @js(old('items.' . $p->id . '.physical_stock', '')),
                                    get selisih() { return this.fisik === '' || this.fisik === null ? null : Math.round((Number(this.fisik) - this.sistem) * 1000) / 1000 }
                                }"
                                :class="selisih !== null && selisih !== 0 ? 'bg-accent-50' : ''">
                                <td >
                                    <p class="font-medium text-stone-900">{{ $p->name }}</p>
                                    <p class="text-xs text-stone-500">{{ $p->sku }}</p>
                                </td>
                                <td class="text-right">
                                    {{ \App\Support\Format::jumlah($p->stock) }} <span class="text-stone-500">{{ $p->unit?->name }}</span>
                                    <input type="hidden" name="items[{{ $p->id }}][system_stock]" value="{{ (float) $p->stock }}">
                                </td>
                                <td >
                                    <input type="number" min="0" step="0.001" x-model="fisik" name="items[{{ $p->id }}][physical_stock]"
                                           aria-label="Stok fisik {{ $p->name }}"
                                           class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                                    @error('items.' . $p->id . '.physical_stock') <p class="mt-1 text-xs text-brand-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="text-right font-medium">
                                    <span x-show="selisih === null" class="text-stone-400">-</span>
                                    <span x-show="selisih === 0" x-cloak class="text-brand-700">Cocok</span>
                                    <span x-show="selisih !== null && selisih !== 0" x-cloak
                                          :class="selisih > 0 ? 'text-emerald-700' : 'text-brand-700'"
                                          x-text="(selisih > 0 ? '+' : '') + selisih"></span>
                                </td>
                                <td >
                                    <input type="text" maxlength="255" name="items[{{ $p->id }}][reason]" value="{{ old('items.' . $p->id . '.reason') }}"
                                           placeholder="Wajib bila ada selisih" aria-label="Alasan selisih {{ $p->name }}"
                                           :required="selisih !== null && selisih !== 0"
                                           class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600">
                                    @error('items.' . $p->id . '.reason') <p class="mt-1 text-xs text-brand-600">{{ $message }}</p> @enderror
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
                    <a href="{{ route('stock-opnames.index') }}" class="tombol-kedua">Batal</a>
                    <button type="submit"
                            class="tombol-utama">Simpan opname</button>
                </div>
            </div>
        </form>
    </div>
</x-app-layout>
