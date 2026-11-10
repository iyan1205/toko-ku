{{-- Tambah / ubah produk. Stok tidak diubah di sini: setiap perubahan stok harus tercatat di kartu stok. --}}
@use('App\Support\Format')
@php
    $edit = $produk->exists;
    $harga = [
        'beli' => (float) old('purchase_price', $produk->purchase_price ?? 0),
        'jual' => (float) old('selling_price', $produk->selling_price ?? 0),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah produk' : 'Tambah produk' }}</x-slot>
    <x-slot name="description">{{ $edit ? $produk->name.' · '.$produk->sku : 'Produk baru di katalog. Stok awal 0, isi lewat pembelian atau penyesuaian stok.' }}</x-slot>

    <form method="POST" enctype="multipart/form-data" action="{{ $edit ? route('products.update', $produk) : route('products.store') }}"
          x-data="{ ...{{ \Illuminate\Support\Js::from($harga) }}, rp(v) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Number(v) || 0) } }"
          class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Identitas" description="SKU dibuat otomatis bila dikosongkan. Barcode bisa diisi dengan scanner.">
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="sku" label="SKU" :value="$produk->sku" maxlength="50" :hint="$edit ? 'Harus unik.' : 'Kosongkan agar dibuat otomatis (PRD-00001).'" />
                <x-ui.input name="barcode" label="Barcode" :value="$produk->barcode" maxlength="50" x-on:keydown.enter.prevent=""
                            hint="Klik lalu scan; Enter dari scanner tidak mengirim formulir." />
            </div>
            <x-ui.input name="name" label="Nama produk" :value="$produk->name" maxlength="150" required />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.select name="category_id" label="Kategori" :options="$kategori->pluck('name', 'id')" :selected="$produk->category_id" placeholder="Pilih kategori" required
                             :hint="$kategori->isEmpty() ? 'Belum ada kategori. Buat dulu di menu Kategori.' : null" />
                <x-ui.select name="unit_id" label="Satuan" :options="$satuan->pluck('name', 'id')" :selected="$produk->unit_id" placeholder="Pilih satuan" required
                             :hint="$satuan->isEmpty() ? 'Belum ada satuan. Buat dulu di menu Satuan.' : null" />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Harga & stok minimum" description="Harga beli diperbarui otomatis oleh pembelian dan penerimaan barang (bisa dimatikan per transaksi).">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <x-ui.input name="purchase_price" label="Harga beli" type="number" prefix="Rp" :value="$produk->purchase_price ?? 0" min="0" step="0.01" required x-model.number="beli" />
                    <p class="mt-1 text-xs text-slate-500" x-text="rp(beli)"></p>
                </div>
                <div>
                    <x-ui.input name="selling_price" label="Harga jual" type="number" prefix="Rp" :value="$produk->selling_price ?? 0" min="0" step="0.01" required x-model.number="jual" />
                    <p class="mt-1 text-xs text-slate-500" x-text="rp(jual)"></p>
                </div>
            </div>
            <x-ui.alert type="warning" x-show="jual > 0 && jual < beli" x-cloak>Harga jual lebih rendah dari harga beli. Produk ini akan dijual rugi.</x-ui.alert>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="min_stock" label="Stok minimum" type="number" :value="$produk->min_stock ?? 0" min="0" step="0.001" required
                            hint="Ditandai MENIPIS bila total stok semua gudang sama dengan atau di bawah angka ini." />
                <x-ui.input name="discount_percent" label="Diskon" type="number" suffix="%" :value="$produk->discount_percent ?? 0" min="0" max="100" step="0.01" required />
            </div>
            <p class="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                @if ($edit)
                    Total stok saat ini <strong class="tabular-nums text-slate-900">{{ Format::jumlah($produk->stock) }} {{ $produk->unit?->name }}</strong>.
                @else
                    Stok awal 0.
                @endif
                Stok tidak diubah dari formulir ini supaya setiap perubahan tercatat di kartu stok.
            </p>
        </x-ui.form-section>

        <x-ui.form-section title="Foto & deskripsi" description="Opsional. Foto tampil di daftar produk dan kasir.">
            @if ($edit && $produk->photoUrl())
                <div class="flex items-center gap-4">
                    <img src="{{ $produk->photoUrl() }}" alt="Foto {{ $produk->name }}" class="h-20 w-20 rounded-lg object-cover">
                    <x-ui.checkbox name="remove_photo" label="Hapus foto ini" />
                </div>
            @endif
            <div>
                <label for="photo" class="block text-sm font-medium text-slate-700">Foto produk</label>
                <input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp"
                       class="mt-1 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                <p class="mt-1 text-xs text-slate-500">JPG, PNG, atau WEBP, maksimal 2 MB.</p>
                @error('photo') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
            </div>
            <x-ui.textarea name="description" label="Deskripsi" :value="$produk->description" rows="3" maxlength="2000" />
            <x-ui.checkbox name="is_active" label="Produk aktif dan dijual" :checked="$edit ? $produk->is_active : true" unchecked-value="0" />

            <x-slot:footer>
                <x-ui.button :href="$edit ? route('products.show', $produk) : route('products.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan produk' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
