{{-- Definisi pemecahan bahan: bahan + jumlahnya, dan hasil per sekali pecah. --}}
@use('App\Models\ProductBreakdown')
@php
    $edit = $breakdown->exists;
    $hasilAwal = collect(old('outputs', $edit ? $breakdown->outputs->map(fn ($o) => ['product_id' => (string) $o->product_id, 'quantity' => (string) (float) $o->quantity])->all() : [['product_id' => '', 'quantity' => '']]))->values();
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah pemecahan bahan' : 'Pemecahan bahan baru' }}</x-slot>
    <x-slot name="description">Contoh: 1 pack Karkas 1 dipotong menjadi 3 Ayam Original Dada, 2 Paha Atas, 2 Paha Bawah, 2 Sayap.</x-slot>

    <form method="POST" action="{{ $edit ? route('breakdowns.update', $breakdown) : route('breakdowns.store') }}" class="grid max-w-3xl gap-6"
          x-data="{ hasil: @js($hasilAwal), get total() { return this.hasil.reduce((s, h) => s + (Number(h.quantity) || 0), 0) } }">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Bahan" description="Bahan yang dipecah dan jumlahnya untuk sekali pecah.">
            <div class="grid gap-5 sm:grid-cols-3">
                <x-ui.select name="source_product_id" label="Bahan" required placeholder="Pilih bahan" class="sm:col-span-2"
                             :options="$produk->mapWithKeys(fn ($p) => [$p->id => $p->name.' ('.$p->unit?->name.')'.($p->type === 'bahan_baku' ? ' · bahan baku' : '')])" :selected="$breakdown->source_product_id" />
                <x-ui.input name="source_quantity" label="Jumlah per pecah" type="number" min="0.001" step="any" required :value="(float) $breakdown->source_quantity" hint="Biasanya 1 (satu pack)." />
            </div>
        </x-ui.form-section>

        <x-ui.form-section title="Hasil per sekali pecah" description="Semua hasil bertambah bersamaan saat bahan dipecah.">
            <template x-for="(h, i) in hasil" x-bind:key="i">
                <div class="grid gap-2 sm:grid-cols-12 sm:items-end">
                    <div class="sm:col-span-8">
                        <label class="text-xs text-slate-500" x-bind:for="'o-p-' + i">Produk hasil</label>
                        <select x-bind:id="'o-p-' + i" x-bind:name="'outputs[' + i + '][product_id]'" x-model="h.product_id" class="{{ $input }}" required>
                            <option value="">Pilih produk</option>
                            @foreach ($produk as $p)
                                <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->unit?->name }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-3">
                        <label class="text-xs text-slate-500" x-bind:for="'o-q-' + i">Jumlah</label>
                        <input x-bind:id="'o-q-' + i" type="number" min="0.001" step="any" required x-bind:name="'outputs[' + i + '][quantity]'" x-model="h.quantity" class="{{ $input }}">
                    </div>
                    <button type="button" class="text-sm font-medium text-danger-600 hover:underline sm:col-span-1 sm:pb-2" x-on:click="hasil.splice(i, 1)" x-bind:disabled="hasil.length === 1">Hapus</button>
                </div>
            </template>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <x-ui.button type="button" variant="secondary" size="sm" icon="plus" x-on:click="hasil.push({ product_id: '', quantity: '' })">Tambah hasil</x-ui.button>
                <p class="text-sm text-slate-600">Total hasil: <strong class="tabular-nums text-slate-900" x-text="total"></strong></p>
            </div>
            @foreach ($errors->get('outputs*') as $pesan)
                <p class="text-sm text-danger-600">{{ $pesan[0] }}</p>
            @endforeach
        </x-ui.form-section>

        <x-ui.form-section title="Biaya & status">
            <x-ui.select name="cost_method" label="Pembagian biaya bahan ke hasil" required :options="ProductBreakdown::COST_METHODS" :selected="$breakdown->cost_method"
                         hint="Rata per satuan hasil: 1 pack Rp 45.000 menjadi 9 potong = Rp 5.000 per potong." />
            <x-ui.input name="notes" label="Catatan" maxlength="255" :value="$breakdown->notes" />
            <x-ui.checkbox name="is_active" label="Aktif" :checked="(bool) $breakdown->is_active" unchecked-value="0" />
            <x-slot:footer>
                <x-ui.button variant="secondary" :href="route('breakdowns.index')">Batal</x-ui.button>
                <x-ui.button type="submit">Simpan</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
