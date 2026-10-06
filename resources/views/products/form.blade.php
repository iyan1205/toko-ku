@php
    $edit = $produk->exists;
    $kategoriDipilih = old('category_id', $produk->category_id);
    $satuanDipilih = old('unit_id', $produk->unit_id);
    $aktif = old('is_active', $edit ? $produk->is_active : true);
    $selectClass = 'mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah produk' : 'Tambah produk' }}</x-slot>

    <form method="POST" enctype="multipart/form-data"
          action="{{ $edit ? route('products.update', $produk) : route('products.store') }}"
          x-data="{
              beli: @js((float) old('purchase_price', $produk->purchase_price ?? 0)),
              jual: @js((float) old('selling_price', $produk->selling_price ?? 0)),
              rp(v) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Number(v) || 0) }
          }"
          class="max-w-3xl space-y-5 rounded-lg border border-stone-200 bg-white p-6">
        @csrf
        @if ($edit) @method('PUT') @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <x-isian label="SKU" name="sku" :value="$produk->sku" maxlength="50"
                     hint="{{ $edit ? 'Harus unik.' : 'Kosongkan agar dibuat otomatis (PRD-00001).' }}" />
            <x-isian label="Barcode" name="barcode" :value="$produk->barcode" maxlength="50"
                     x-on:keydown.enter.prevent="$event"
                     hint="Klik kolom ini lalu scan barcode; Enter dari scanner tidak akan mengirim formulir." />
        </div>

        <x-isian label="Nama produk" name="name" :value="$produk->name" :wajib="true" maxlength="150" />

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="category_id" class="block text-sm font-medium text-stone-700">Kategori</label>
                <select id="category_id" name="category_id" required class="{{ $selectClass }}">
                    <option value="">Pilih kategori</option>
                    @foreach ($kategori as $k)
                        <option value="{{ $k->id }}" @selected((string) $kategoriDipilih === (string) $k->id)>{{ $k->name }}</option>
                    @endforeach
                </select>
                @error('category_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @if ($kategori->isEmpty())
                    <p class="mt-1 text-xs text-amber-700">Belum ada kategori. Buat dulu di menu Kategori.</p>
                @endif
            </div>
            <div>
                <label for="unit_id" class="block text-sm font-medium text-stone-700">Satuan</label>
                <select id="unit_id" name="unit_id" required class="{{ $selectClass }}">
                    <option value="">Pilih satuan</option>
                    @foreach ($satuan as $s)
                        <option value="{{ $s->id }}" @selected((string) $satuanDipilih === (string) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
                @error('unit_id') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                @if ($satuan->isEmpty())
                    <p class="mt-1 text-xs text-amber-700">Belum ada satuan. Buat dulu di menu Satuan.</p>
                @endif
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <x-isian label="Harga beli (Rp)" name="purchase_price" type="number" :value="$produk->purchase_price ?? 0" :wajib="true"
                         min="0" step="0.01" x-model.number="beli" />
                <p class="mt-1 text-xs text-stone-500" x-text="rp(beli)"></p>
            </div>
            <div>
                <x-isian label="Harga jual (Rp)" name="selling_price" type="number" :value="$produk->selling_price ?? 0" :wajib="true"
                         min="0" step="0.01" x-model.number="jual" />
                <p class="mt-1 text-xs text-stone-500" x-text="rp(jual)"></p>
            </div>
        </div>

        <p x-show="jual > 0 && jual < beli" x-cloak class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">
            Harga jual lebih rendah dari harga beli. Produk ini akan dijual rugi.
        </p>

        <div class="grid gap-5 sm:grid-cols-2">
            <x-isian label="Stok minimum" name="min_stock" type="number" :value="$produk->min_stock ?? 0" :wajib="true" min="0" step="0.001"
                     hint="Produk ditandai MENIPIS bila stok sama dengan atau di bawah angka ini." />
            <x-isian label="Diskon (%)" name="discount_percent" type="number" :value="$produk->discount_percent ?? 0" :wajib="true" min="0" max="100" step="0.01" />
        </div>

        <div class="rounded-md bg-stone-50 px-4 py-3 text-sm text-stone-600">
            @if ($edit)
                Stok saat ini <span class="font-semibold text-stone-900">{{ \App\Support\Format::jumlah($produk->stock) }}</span>.
            @else
                Stok awal 0.
            @endif
            Stok tidak diubah dari formulir ini, supaya setiap perubahan tercatat di riwayat stok.
            Isi stok lewat Pembelian atau Stok Opname.
        </div>

        <div>
            <label for="photo" class="block text-sm font-medium text-stone-700">Foto produk <span class="font-normal text-stone-400">(opsional)</span></label>
            @if ($edit && $produk->photoUrl())
                <div class="mt-2 flex items-center gap-4">
                    <img src="{{ $produk->photoUrl() }}" alt="Foto {{ $produk->name }}" class="h-20 w-20 rounded-md object-cover">
                    <label class="flex items-center gap-2 text-sm text-stone-600">
                        <input type="checkbox" name="remove_photo" value="1" class="rounded border-stone-300 text-teal-700 focus:ring-teal-600">
                        Hapus foto ini
                    </label>
                </div>
            @endif
            <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"
                   class="mt-2 block w-full text-sm text-stone-600 file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-stone-700 hover:file:bg-stone-200">
            <p class="mt-1 text-xs text-stone-500">JPG, PNG, atau WEBP, maksimal 2 MB.</p>
            @error('photo') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="description" class="block text-sm font-medium text-stone-700">Deskripsi <span class="font-normal text-stone-400">(opsional)</span></label>
            <textarea id="description" name="description" rows="3" maxlength="2000" class="{{ $selectClass }}">{{ old('description', $produk->description) }}</textarea>
            @error('description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <label for="is_active" class="flex items-center gap-2 text-sm text-stone-700">
            <input id="is_active" name="is_active" type="checkbox" value="1" @checked($aktif)
                   class="rounded border-stone-300 text-teal-700 focus:ring-teal-600">
            Produk aktif dan dijual
        </label>

        <div class="flex justify-end gap-2 border-t border-stone-100 pt-5">
            <a href="{{ $edit ? route('products.show', $produk) : route('products.index') }}"
               class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit"
                    class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                {{ $edit ? 'Simpan perubahan' : 'Simpan produk' }}
            </button>
        </div>
    </form>
</x-app-layout>
