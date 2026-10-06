@php
    $produkDipilih = old('product_id', $dipilih);
    $jenisDipilih = old('type', 'adjustment');
    $selectClass = 'mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600';
@endphp

<x-app-layout>
    <x-slot name="header">Penyesuaian stok</x-slot>

    <form method="POST" action="{{ route('stock.adjust.store') }}"
          x-data="{ tipe: @js($jenisDipilih) }"
          class="max-w-2xl space-y-5 rounded-lg border border-stone-200 bg-white p-6">
        @csrf

        <p class="rounded-md bg-stone-50 px-4 py-3 text-sm text-stone-600">
            Gunakan untuk stok awal, koreksi, barang rusak, atau hilang. Setiap penyesuaian tercatat permanen di riwayat mutasi
            dan tidak bisa diubah. Untuk mencocokkan stok dengan hitungan fisik banyak produk, gunakan Stok Opname.
        </p>

        <div>
            <label for="product_id" class="block text-sm font-medium text-stone-700">Produk</label>
            <select id="product_id" name="product_id" required class="{{ $selectClass }}">
                <option value="">Pilih produk</option>
                @foreach ($produk as $p)
                    <option value="{{ $p->id }}" @selected((string) $produkDipilih === (string) $p->id)>
                        {{ $p->sku }} · {{ $p->name }} (stok {{ \App\Support\Format::jumlah($p->stock) }} {{ $p->unit?->name }})
                    </option>
                @endforeach
            </select>
            @error('product_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="text-sm font-medium text-stone-700">Jenis</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                @foreach ($jenis as $j)
                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 p-3 text-sm has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                        <input type="radio" name="type" value="{{ $j->value }}" x-model="tipe" @checked($jenisDipilih === $j->value)
                               class="border-stone-300 text-teal-700 focus:ring-teal-600">
                        {{ $j->label() }}
                    </label>
                @endforeach
            </div>
            @error('type') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset x-show="tipe === 'adjustment'" x-cloak>
            <legend class="text-sm font-medium text-stone-700">Arah penyesuaian</legend>
            <div class="mt-2 flex gap-3">
                <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 px-4 py-2 text-sm has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                    <input type="radio" name="direction" value="in" @checked(old('direction', 'in') === 'in') class="border-stone-300 text-teal-700 focus:ring-teal-600">
                    Tambah stok
                </label>
                <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 px-4 py-2 text-sm has-[:checked]:border-red-500 has-[:checked]:bg-red-50">
                    <input type="radio" name="direction" value="out" @checked(old('direction') === 'out') class="border-stone-300 text-red-600 focus:ring-red-500">
                    Kurangi stok
                </label>
            </div>
            @error('direction') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <p x-show="tipe !== 'adjustment'" x-cloak class="text-sm text-stone-500">Barang rusak dan hilang selalu mengurangi stok.</p>

        <x-isian label="Jumlah" name="quantity" type="number" :wajib="true" min="0.001" step="0.001"
                 hint="Gunakan angka desimal untuk satuan seperti KG atau LITER." />

        <x-isian label="Alasan" name="note" :wajib="true" maxlength="255"
                 hint="Contoh: stok awal, botol pecah saat bongkar muat, selisih hitungan rak." />

        <div class="flex justify-end gap-2 border-t border-stone-100 pt-5">
            <a href="{{ route('stock.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit"
                    class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">Simpan penyesuaian</button>
        </div>
    </form>
</x-app-layout>
