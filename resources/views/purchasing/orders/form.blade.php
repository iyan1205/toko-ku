{{--
    Purchase order baru / ubah draft. Simpan sebagai draft, langsung setujui (butuh izin approve), atau
    ajukan ke alur persetujuan. PO yang cocok dengan alur persetujuan selalu diajukan, tidak disetujui langsung.
    PO tidak mengubah stok maupun hutang; keduanya tercatat saat barang diterima.
--}}
@php
    $edit = $order->exists;
    $bolehSetujui = auth()->user()->can('purchasing.orders.approve');
    $awal = [
        'urlCari' => route('purchase-orders.products'),
        'items' => $items,
        'diskon' => old('discount', $edit ? (float) $order->discount : 0),
        'pajak' => old('tax', $edit ? (float) $order->tax : 0),
        'biaya' => old('other_cost', $edit ? (float) $order->other_cost : 0),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah draft '.$order->number : 'Purchase order baru' }}</x-slot>
    <x-slot name="description">Pesanan ke supplier. Stok dan hutang baru tercatat saat barang diterima.</x-slot>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-6" title="Purchase order belum bisa disimpan">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ $edit ? route('purchase-orders.update', $order) : route('purchase-orders.store') }}"
          x-data="dokumenBarang(@js($awal))" x-on:submit="kirim = true" class="grid gap-6">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif
        <input type="hidden" name="aksi" value="draft" x-ref="aksi">

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.select name="supplier_id" label="Supplier" required :options="$suppliers->pluck('name', 'id')" :selected="$order->supplier_id" placeholder="Pilih supplier"
                             :hint="$suppliers->isEmpty() ? 'Belum ada supplier. Tambahkan dulu di menu Supplier.' : null" class="lg:col-span-2" />
                <x-ui.select name="warehouse_id" label="Gudang tujuan" required :options="$gudang->pluck('name', 'id')" :selected="$order->warehouse_id"
                             hint="Bisa diganti saat barang diterima." />
                <x-ui.input name="order_date" label="Tanggal PO" type="date" :value="$order->order_date?->format('Y-m-d')" required />
                <x-ui.input name="expected_date" label="Perkiraan tiba" type="date" :value="$order->expected_date?->format('Y-m-d')" />
            </div>
        </x-ui.card>

        <x-ui.card :padding="false">
            @include('partials.cari-produk', ['label' => 'Tambah produk'])
            @include('partials.baris-harga')
        </x-ui.card>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-ui.card>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-ui.input name="discount" label="Diskon" type="number" prefix="Rp" min="0" step="0.01" x-model="diskon" />
                    <x-ui.input name="tax" label="Pajak" type="number" prefix="Rp" min="0" step="0.01" x-model="pajak" />
                    <x-ui.input name="other_cost" label="Biaya lain" type="number" prefix="Rp" min="0" step="0.01" x-model="biaya" hint="Misal ongkos kirim." />
                </div>
                <x-ui.textarea name="note" label="Catatan untuk supplier" :value="$order->note" rows="2" maxlength="1000" class="mt-5" />
            </x-ui.card>
            <x-ui.card>
                @include('partials.ringkasan-total')
                <p class="mt-4 text-xs text-slate-500">Diskon, pajak, dan biaya lain dibagi ke setiap penerimaan sebanding nilai barang yang diterima.</p>
            </x-ui.card>
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <x-ui.button :href="$edit ? route('purchase-orders.show', $order) : route('purchase-orders.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" variant="secondary" icon="document" x-on:click="$refs.aksi.value = 'draft'" x-bind:disabled="kirim || items.length === 0">Simpan draft</x-ui.button>
            @if ($bolehSetujui)
                <x-ui.button type="submit" icon="check" x-on:click="$refs.aksi.value = 'approve'" x-bind:disabled="kirim || items.length === 0">Simpan &amp; setujui</x-ui.button>
            @else
                <x-ui.button type="submit" icon="paper-airplane" x-on:click="$refs.aksi.value = 'submit'" x-bind:disabled="kirim || items.length === 0">Simpan &amp; ajukan</x-ui.button>
            @endif
        </div>
    </form>
</x-app-layout>
