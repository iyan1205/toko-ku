@php
    $produkDipilih = old('product_id') ? (int) old('product_id') : ($dipilih ?? null);
    $jenisDipilih = old('type', 'adjustment');
    $selectClass = 'block w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600';
@endphp

<x-app-layout>
    <x-slot name="header">Penyesuaian stok</x-slot>

    <div class="max-w-2xl space-y-5 rounded-lg border border-stone-200 bg-white p-6">
        @csrf

        <p class="rounded-md bg-stone-50 px-4 py-3 text-sm text-stone-600">
            Pilih produk dari daftar dropdown. Klik untuk buka daftar pilihan.
        </p>

        <div>
            <label for="product_id" class="block text-sm font-medium text-stone-700 mb-1">Produk</label>
            <select id="product_id" name="product_id" required class="@apply {{ $selectClass }}">
                <option value="">Pilih produk...</option>
                @foreach ($produk as $p)
                    <option value="{{ $p->id }}" {{ $produkDipilih == $p->id ? 'selected' : '' }}>
                        {{ $p->sku }} — {{ $p->name }} (stok: {{ \App\Support\Format::jumlah($p->stock) }} {{ $p->unit?->name }})
                    </option>
                @endforeach
            </select>
            @error('product_id') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
        </div>

        <fieldset>
            <legend class="text-sm font-medium text-stone-700 mb-2">Jenis</legend>
            <div class="mt-2 grid gap-2 sm:grid-cols-3">
                @foreach ($jenis as $j)
                    <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 p-3 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                        <input type="radio" name="type" value="{{ $j->value }}" @checked($jenisDipilih === $j->value)
                               class="border-stone-300 text-brand-700 focus:ring-brand-600">
                        {{ $j->label() }}
                    </label>
                @endforeach
            </div>
            @error('type') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
        </fieldset>

        <div>
            <h4 class="text-sm font-medium text-stone-700 mb-2">Arah penyesuaian</h4>
            <div class="flex gap-3">
                <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 px-4 py-2 text-sm has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50">
                    <input type="radio" name="direction" value="in" @checked(old('direction', 'in') === 'in') class="border-stone-300 text-brand-700 focus:ring-brand-600">
                    Tambah stok
                </label>
                <label class="flex cursor-pointer items-center gap-2 rounded-md border border-stone-200 px-4 py-2 text-sm has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
                    <input type="radio" name="direction" value="out" @checked(old('direction') === 'out') class="border-stone-300 text-brand-600 focus:ring-brand-500">
                    Kurangi stok
                </label>
            </div>
            @error('direction') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
        </div>

        <x-isian label="Jumlah" name="quantity" type="number" :wajib="true" min="0.001" step="0.001"
                 hint="Gunakan angka desimal untuk satuan seperti KG atau LITER." />

        <x-isian label="Alasan" name="note" :wajib="true" maxlength="255"
                 hint="Contoh: stok awal, botol pecah saat bongkar muat, selisih hitungan rak." />

        <div class="flex justify-end gap-2 border-t border-stone-100 pt-5">
            <a href="{{ route('stock.index') }}" class="tombol-kedua">Batal</a>
            <button type="submit"
                    class="tombol-utama">Simpan penyesuaian</button>
        </div>
    </div>
</x-app-layout>