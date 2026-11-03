<x-app-layout>
    <x-slot name="header">{{ isset($recipe) ? 'Ubah Resep' : 'Buat Resep' }}</x-slot>

    @php
        $edit = isset($recipe);
        $items = old('items', $edit ? $recipe->items->map(fn ($i) => ['product_id' => $i->product_id, 'quantity' => (string) $i->quantity, 'notes' => $i->notes])->toArray() : [['product_id' => '', 'quantity' => '', 'notes' => '']]);
    @endphp

    <form method="POST" action="{{ $edit ? route('recipes.update', $recipe) : route('recipes.store') }}"
          class="space-y-6" x-data="{
              items: @js($items),
              produk: @js($bahanList->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'unit' => $b->unit?->name, 'price' => $b->purchase_price])),
              tambah() { this.items.push({product_id: '', quantity: '', notes: ''}) },
              hapus(i) { if (this.items.length > 1) this.items.splice(i, 1) },
              namaBahan(id) { const p = this.produk.find(x => x.id == id); return p ? p.name : '-' },
              satuanBahan(id) { const p = this.produk.find(x => x.id == id); return p ? (p.unit ?? '') : '' },
              hargaBahan(id) { const p = this.produk.find(x => x.id == id); return p ? p.price : 0 },
              baris(i) { return (parseFloat(i.quantity) || 0) * this.hargaBahan(i.product_id) },
              total() { return this.items.reduce((s, i) => s + this.baris(i), 0) },
              rp(v) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(v) || 0) }
          }">
        @csrf
        @if ($edit) @method('PUT') @endif

        <div class="kartu p-6">
            <h2 class="judul-bagian">Produk yang dibuat</h2>
            <p class="mt-1 text-sm text-stone-500">Pilih produk jual, lalu tentukan bahan-bahan yang diproduksi dari produk tersebut.</p>

            <div class="mt-4 max-w-md">
                <label for="product_id" class="label-isian">Produk Jual <span class="text-brand-600">*</span></label>
                <select id="product_id" name="product_id" required
                        class="isian mt-1 @error('product_id') isian-salah @enderror">
                    <option value="">Pilih produk jual</option>
                    @foreach ($produkList as $p)
                        <option value="{{ $p->id }}" @selected((string) old('product_id', $edit ? $recipe->product_id ?? '' : '') === (string) $p->id)>
                            {{ $p->name }} — Rp {{ number_format($p->selling_price, 0, ',', '.') }}
                        </option>
                    @endforeach
                </select>
                @error('product_id')
                    <p class="mt-1 text-sm text-brand-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-5">
                <label for="notes" class="label-isian">Catatan <span class="font-normal text-stone-400">(opsional)</span></label>
                <textarea id="notes" name="notes" rows="2" maxlength="500"
                          class="isian mt-1 max-w-lg @error('notes') isian-salah @enderror">{{ old('notes', $edit ? $recipe->notes ?? '' : '') }}</textarea>
                @error('notes')
                    <p class="mt-1 text-sm text-brand-600">{{ $message }}</p>
                @enderror
            </div>
        </div>

        <div class="kartu p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="judul-bagian">Bahan yang dipakai</h2>
                <button type="button" @click="tambah()" class="tombol-kedua">+ Tambah Bahan</button>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th class="w-1/2">Bahan</th>
                            <th class="w-32 text-right">Jumlah</th>
                            <th class="w-24">Satuan</th>
                            <th class="w-32 text-right">Harga</th>
                            <th class="w-36 text-right">Subtotal</th>
                            <th class="w-16"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, i) in items" :key="i">
                            <tr>
                                <td>
                                    <select
                                        :name="`items[${i}][product_id]`"
                                        x-model="item.product_id"
                                        class="w-full rounded-md border-stone-300 text-sm focus:border-brand-600 focus:ring-brand-600"
                                    >
                                        <option value="">Pilih bahan</option>
                                        @foreach ($bahanList as $b)
                                            <option value="{{ $b->id }}">
                                                {{ $b->name }} ({{ $b->unit?->name ?? '-' }})
                                            </option>
                                        @endforeach
                                    </select>

                                    <p class="mt-1 text-xs text-brand-600"
                                    x-show="errors[`items.${i}.product_id`]"
                                    x-text="errors[`items.${i}.product_id`]?.[0] || ''"></p>
                                </td>

                                <td>
                                    <input
                                        type="number"
                                        step="0.0001"
                                        min="0.0001"
                                        :name="`items[${i}][quantity]`"
                                        x-model="item.quantity"
                                        class="w-full rounded-md border-stone-300 text-right text-sm focus:border-brand-600 focus:ring-brand-600"
                                    >

                                    <p class="mt-1 text-xs text-brand-600"
                                    x-show="errors[`items.${i}.quantity`]"
                                    x-text="errors[`items.${i}.quantity`]?.[0] || ''"></p>
                                </td>

                                <td class="text-stone-600"
                                    x-text="satuanBahan(item.product_id)">-</td>

                                <td class="text-right text-stone-600 angka-rata"
                                    x-text="rp(hargaBahan(item.product_id)">-</td>

                                <td class="text-right font-medium text-stone-900 angka-rata"
                                    x-text="rp(baris(item))">-</td>

                                <td>
                                    <button
                                        type="button"
                                        @click="hapus(i)"
                                        x-show="items.length > 1"
                                        class="text-brand-700 hover:text-brand-800"
                                        aria-label="Hapus baris"
                                    >&times;</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="font-semibold text-stone-900">Total Biaya Bahan</td>
                            <td class="text-right font-bold text-stone-900 angka-rata" x-text="rp(total())">Rp 0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="mt-3 text-sm text-stone-500">
                Total biaya bahan ini = biaya untuk memproduksi 1 porsi (atau 1 batch, tergantung satuan yang dipakai).
            </p>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('recipes.index') }}" class="tombol-kedua">Batal</a>
            <button type="submit" class="tombol-utama">Simpan Resep</button>
        </div>
    </form>
</x-app-layout>
