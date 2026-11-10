{{--
    Buat / ubah resep (BOM): satu produk jual dan bahan baku per 1 porsi.
    Biaya di layar hanya pratinjau dari harga beli bahan saat ini; server menghitung ulang.
--}}
@php
    $edit = $recipe !== null;
    $items = old('items', $edit
        ? $recipe->items->map(fn ($i) => ['product_id' => (string) $i->product_id, 'quantity' => (string) (float) $i->quantity, 'notes' => (string) $i->notes])->all()
        : [['product_id' => '', 'quantity' => '', 'notes' => '']]);
    $awal = [
        'items' => array_values($items),
        'produk' => $bahanList->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'unit' => $b->unit?->name, 'price' => (float) $b->purchase_price])->values(),
        // Pesan error server per baris, misal "items.0.quantity".
        'errors' => collect($errors->getMessages())->filter(fn ($m, $key) => str_starts_with($key, 'items.')),
    ];
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah resep '.($recipe->product?->name ?? '') : 'Buat resep' }}</x-slot>
    <x-slot name="description">Bahan baku untuk membuat 1 porsi produk jual. Biaya bahan mengikuti harga beli terbaru.</x-slot>

    <form method="POST" action="{{ $edit ? route('recipes.update', $recipe) : route('recipes.store') }}" class="grid gap-6"
          x-data="{
              ...@js($awal),
              tambah() { this.items.push({ product_id: '', quantity: '', notes: '' }) },
              hapus(i) { if (this.items.length > 1) this.items.splice(i, 1) },
              bahan(id) { return this.produk.find((p) => String(p.id) === String(id)) ?? null },
              baris(item) { return (parseFloat(item.quantity) || 0) * (this.bahan(item.product_id)?.price ?? 0) },
              total() { return this.items.reduce((s, item) => s + this.baris(item), 0) },
              galat(i, kolom) { return this.errors['items.' + i + '.' + kolom]?.[0] ?? '' },
              rp(v) { return 'Rp ' + new Intl.NumberFormat('id-ID').format(Math.round(v) || 0) },
          }">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.card title="Produk yang dibuat">
            <div class="grid gap-5 lg:grid-cols-2">
                <x-ui.select name="product_id" label="Produk jual" required placeholder="Pilih produk jual"
                             :options="$produkList->mapWithKeys(fn ($p) => [$p->id => $p->name.' — '.\App\Support\Format::rupiah($p->selling_price)])"
                             :selected="$edit ? $recipe->product_id : null" />
                <x-ui.textarea name="notes" label="Catatan" rows="2" maxlength="500" :value="$edit ? $recipe->notes : null" />
            </div>
        </x-ui.card>

        <x-ui.card title="Bahan yang dipakai" :padding="false">
            <x-slot:actions>
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="tambah()">Tambah bahan</x-ui.button>
            </x-slot:actions>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="{{ $th }}">Bahan</th>
                            <th class="{{ $th }} w-32">Jumlah</th>
                            <th class="{{ $th }} w-24">Satuan</th>
                            <th class="{{ $th }} w-32 text-right">Harga beli</th>
                            <th class="{{ $th }} w-36 text-right">Subtotal</th>
                            <th class="w-12 px-2 py-3"><span class="sr-only">Hapus</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(item, i) in items" x-bind:key="i">
                            <tr class="align-top">
                                <td class="px-4 py-3">
                                    <select x-bind:name="'items[' + i + '][product_id]'" x-model="item.product_id" class="{{ $input }}" x-bind:aria-label="'Bahan baris ' + (i + 1)" required>
                                        <option value="">Pilih bahan</option>
                                        @foreach ($bahanList as $b)
                                            <option value="{{ $b->id }}">{{ $b->name }} ({{ $b->unit?->name ?? '-' }})</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'product_id')" x-text="galat(i, 'product_id')"></p>
                                    <input type="text" x-bind:name="'items[' + i + '][notes]'" x-model="item.notes" maxlength="200" placeholder="Catatan bahan (opsional)"
                                           class="{{ $input }} mt-2" x-bind:aria-label="'Catatan bahan baris ' + (i + 1)">
                                </td>
                                <td class="px-4 py-3">
                                    <input type="number" step="0.0001" min="0.0001" required x-bind:name="'items[' + i + '][quantity]'" x-model="item.quantity"
                                           class="{{ $input }} text-right" x-bind:aria-label="'Jumlah bahan baris ' + (i + 1)">
                                    <p class="mt-1 text-xs text-danger-600" x-show="galat(i, 'quantity')" x-text="galat(i, 'quantity')"></p>
                                </td>
                                <td class="px-4 py-3 text-slate-600" x-text="bahan(item.product_id)?.unit ?? '—'"></td>
                                <td class="px-4 py-3 text-right tabular-nums text-slate-600" x-text="rp(bahan(item.product_id)?.price ?? 0)"></td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-slate-900" x-text="rp(baris(item))"></td>
                                <td class="px-2 py-3 text-right">
                                    <x-ui.button type="button" variant="ghost" size="sm" icon="trash" class="text-danger-600 hover:text-danger-700"
                                                 x-show="items.length > 1" x-on:click="hapus(i)" x-bind:aria-label="'Hapus baris ' + (i + 1)" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-slate-50">
                        <tr>
                            <td colspan="4" class="px-4 py-3 text-sm font-semibold text-slate-900">Total biaya bahan per porsi</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900" x-text="rp(total())"></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-slate-200 px-5 py-3 text-xs text-slate-500">
                Isi jumlah per 1 porsi. Misal satu porsi memakai 0,111 potong ayam, isi 0,111.
            </p>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="$edit ? route('recipes.show', $recipe) : route('recipes.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check">Simpan resep</x-ui.button>
        </div>
    </form>
</x-app-layout>
