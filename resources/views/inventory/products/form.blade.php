{{-- Tambah / ubah produk. Stok tidak diubah di sini: setiap perubahan stok harus tercatat di kartu stok. --}}
@use('App\Support\Format')
@php
    $edit = $produk->exists;
    $harga = [
        'beli' => (float) old('purchase_price', $produk->purchase_price ?? 0),
        'jual' => (float) old('selling_price', $produk->selling_price ?? 0),
        'satuanLain' => collect(old('units', $produk->exists ? $produk->units->map(fn ($u) => [
            'unit_id' => (string) $u->unit_id, 'factor' => (string) (float) $u->factor, 'barcode' => $u->barcode, 'selling_price' => $u->selling_price !== null ? (string) (float) $u->selling_price : '',
        ])->all() : []))->values(),
        'varian' => collect(old('variant_options', $produk->variant_options ?? []))->map(fn ($o) => [
            'name' => $o['name'] ?? '', 'values' => is_array($o['values'] ?? null) ? implode(', ', $o['values']) : (string) ($o['values'] ?? ''),
        ])->values(),
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
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

        <x-ui.form-section title="Satuan lain" description="Opsional. Misal DUS isi 12 PCS: jual per dus di kasir (scan barcode dus), stok tetap dihitung dalam satuan dasar.">
            <template x-for="(u, i) in satuanLain" x-bind:key="i">
                <div class="grid gap-2 sm:grid-cols-12 sm:items-end">
                    <div class="sm:col-span-3">
                        <label class="text-xs text-slate-500" x-bind:for="'u-unit-' + i">Satuan</label>
                        <select x-bind:id="'u-unit-' + i" x-bind:name="'units[' + i + '][unit_id]'" x-model="u.unit_id" class="{{ $input }}">
                            <option value="">Pilih</option>
                            @foreach ($satuan as $s)
                                <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs text-slate-500" x-bind:for="'u-factor-' + i">Isi (satuan dasar)</label>
                        <input x-bind:id="'u-factor-' + i" type="number" min="0.0001" step="any" x-bind:name="'units[' + i + '][factor]'" x-model="u.factor" class="{{ $input }}">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="text-xs text-slate-500" x-bind:for="'u-barcode-' + i">Barcode</label>
                        <input x-bind:id="'u-barcode-' + i" type="text" maxlength="50" x-bind:name="'units[' + i + '][barcode]'" x-model="u.barcode" x-on:keydown.enter.prevent="" class="{{ $input }}">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="text-xs text-slate-500" x-bind:for="'u-price-' + i">Harga jual</label>
                        <input x-bind:id="'u-price-' + i" type="number" min="0" step="0.01" x-bind:name="'units[' + i + '][selling_price]'" x-model="u.selling_price"
                               x-bind:placeholder="rp(jual * (Number(u.factor) || 0))" class="{{ $input }}">
                    </div>
                    <button type="button" class="text-sm font-medium text-danger-600 hover:underline sm:col-span-1 sm:pb-2" x-on:click="satuanLain.splice(i, 1)">Hapus</button>
                </div>
            </template>
            <div>
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="satuanLain.push({ unit_id: '', factor: '', barcode: '', selling_price: '' })" x-bind:disabled="satuanLain.length >= 5">Tambah satuan</x-ui.button>
                <p class="mt-1 text-xs text-slate-500">Harga jual kosong = harga satuan dasar × isi.</p>
                @foreach ($errors->get('units.*') as $pesan)
                    <p class="mt-1 text-sm text-danger-600">{{ $pesan[0] }}</p>
                @endforeach
            </div>
        </x-ui.form-section>

        @unless ($produk->parent_id)
            <x-ui.form-section title="Varian & paket" description="Varian: misal Ukuran (S, M, L) dan Warna (Merah, Biru). Setiap kombinasi menjadi produk sendiri dengan SKU, barcode, stok, dan harga sendiri.">
                <template x-for="(o, i) in varian" x-bind:key="i">
                    <div class="grid gap-2 sm:grid-cols-12 sm:items-end">
                        <div class="sm:col-span-4">
                            <label class="text-xs text-slate-500" x-bind:for="'v-name-' + i">Atribut</label>
                            <input x-bind:id="'v-name-' + i" type="text" maxlength="30" placeholder="Ukuran" x-bind:name="'variant_options[' + i + '][name]'" x-model="o.name" class="{{ $input }}">
                        </div>
                        <div class="sm:col-span-7">
                            <label class="text-xs text-slate-500" x-bind:for="'v-values-' + i">Nilai (pisahkan dengan koma)</label>
                            <input x-bind:id="'v-values-' + i" type="text" placeholder="S, M, L, XL" x-bind:name="'variant_options[' + i + '][values]'" x-model="o.values" class="{{ $input }}">
                        </div>
                        <button type="button" class="text-sm font-medium text-danger-600 hover:underline sm:col-span-1 sm:pb-2" x-on:click="varian.splice(i, 1)">Hapus</button>
                    </div>
                </template>
                <div>
                    <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="varian.push({ name: '', values: '' })" x-bind:disabled="varian.length >= 3">Tambah atribut varian</x-ui.button>
                    <p class="mt-1 text-xs text-slate-500" x-show="varian.length" x-cloak>
                        Produk ini menjadi induk: tidak dijual dan tidak punya stok sendiri. Kombinasi baru dibuat saat disimpan; kombinasi yang dihapus dinonaktifkan.
                    </p>
                    @error('variant_options') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>
                <x-ui.checkbox name="is_bundle" label="Produk paket (bundling)" :checked="(bool) $produk->is_bundle" unchecked-value="0"
                               hint="Menjual paket mengurangi stok komponennya. Atur komponen dan jumlahnya di menu Resep/BOM produk ini." />
            </x-ui.form-section>
        @endunless

        <x-ui.form-section title="Pelacakan stok" description="Opsional, untuk barang dengan tanggal kedaluwarsa atau bernomor seri.">
            <x-ui.checkbox name="track_batches" label="Lacak batch & tanggal kedaluwarsa" :checked="(bool) $produk->track_batches" unchecked-value="0"
                           hint="Nomor batch wajib diisi saat barang diterima. Stok keluar memakai batch yang kedaluwarsa paling dekat (FEFO); batch kedaluwarsa tidak dijual di kasir." />
            <x-ui.checkbox name="track_serials" label="Lacak nomor seri per unit" :checked="(bool) $produk->track_serials" unchecked-value="0"
                           hint="Nomor seri wajib diisi saat barang diterima (satu per unit); jumlah selalu bilangan bulat." />
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
            <fieldset class="grid gap-3 rounded-lg border border-slate-200 p-4">
                <legend class="px-1 text-sm font-medium text-slate-700">Penggunaan</legend>
                <x-ui.checkbox name="is_active" label="Produk aktif" :checked="$edit ? $produk->is_active : true" unchecked-value="0"
                               hint="Nonaktif = disembunyikan dari semua transaksi baru; riwayatnya tetap ada." />
                <x-ui.checkbox name="is_sellable" label="Dijual di kasir" :checked="$edit ? $produk->isSellable() : true" unchecked-value="0"
                               hint="Hilangkan centang untuk bahan baku, misal Karkas: hanya dibeli dan diolah, tidak tampil di kasir." />
                <x-ui.checkbox name="is_purchasable" label="Dibeli dari supplier" :checked="$edit ? $produk->is_purchasable : true" unchecked-value="0"
                               hint="Hilangkan centang untuk produk olahan sendiri, misal Ayam Original Dada: stoknya bertambah lewat produksi dari resep." />
            </fieldset>

            <x-slot:footer>
                <x-ui.button :href="$edit ? route('products.show', $produk) : route('products.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan produk' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
