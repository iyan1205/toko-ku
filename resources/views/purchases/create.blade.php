{{--
    Pembelian langsung tanpa purchase order: stok langsung masuk ke gudang yang dipilih, dan sisa
    yang belum dibayar menjadi hutang supplier. Untuk pesanan yang datang bertahap, pakai Purchase Order.
--}}
@php
    $awal = [
        'urlCari' => route('purchases.products'),
        'items' => $items,
        'diskon' => old('discount', 0),
        'pajak' => old('tax', 0),
        'biaya' => old('other_cost', 0),
        'bayar' => old('paid_amount', 0),
        'metode' => old('payment_method', $metode[0]->value ?? 'cash'),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Pembelian baru</x-slot>
    <x-slot name="description">Pembelian langsung tanpa PO. Stok bertambah saat disimpan; sisa yang belum dibayar menjadi hutang.</x-slot>

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-6" title="Pembelian belum bisa disimpan">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('purchases.store') }}" x-data="dokumenBarang(@js($awal))" x-on:submit="kirim = true" class="grid gap-6">
        @csrf

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.select name="supplier_id" label="Supplier" required :options="$suppliers->pluck('name', 'id')" :selected="$supplierDipilih" placeholder="Pilih supplier"
                             :hint="$suppliers->isEmpty() ? 'Belum ada supplier. Tambahkan dulu di menu Supplier.' : null" class="lg:col-span-2" />
                <x-ui.select name="warehouse_id" label="Gudang tujuan" required :options="$gudang->pluck('name', 'id')" :selected="$gudangUtama" />
                <x-ui.input name="purchase_date" label="Tanggal pembelian" type="date" required :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                <x-ui.input name="supplier_invoice_number" label="Nomor faktur supplier" maxlength="50" class="lg:col-span-2"
                            hint="Faktur yang sama dari supplier yang sama hanya bisa dicatat sekali." />
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
                <x-ui.textarea name="note" label="Catatan" rows="2" maxlength="1000" class="mt-5" />
                <x-ui.checkbox name="update_cost" label="Perbarui harga beli produk dengan harga pembelian ini" :checked="old('update_cost', true)" class="mt-5"
                               hint="Memakai harga bersih per unit (setelah diskon baris). Menjadi dasar harga modal penjualan berikutnya; riwayat lama tidak berubah." />
            </x-ui.card>

            <x-ui.card>
                @include('partials.ringkasan-total')

                <div class="mt-5 grid gap-4 border-t border-slate-200 pt-5">
                    <div class="flex items-end gap-2">
                        <x-ui.input name="paid_amount" label="Dibayar sekarang" type="number" prefix="Rp" min="0" step="0.01" x-model="bayar" class="flex-1" />
                        <x-ui.button type="button" variant="secondary" x-on:click="bayar = total">Bayar lunas</x-ui.button>
                    </div>
                    <div x-show="angka(bayar) > 0" x-cloak>
                        <x-ui.select name="payment_method" label="Metode pembayaran" :options="collect($metode)->mapWithKeys(fn ($m) => [$m->value => $m->label()])"
                                     x-model="metode" x-bind:disabled="angka(bayar) <= 0" />
                    </div>
                    <x-ui.input name="due_date" label="Jatuh tempo" type="date" hint="Wajib diisi bila masih ada sisa hutang." />

                    <div class="flex items-center justify-between rounded-lg bg-slate-50 px-4 py-3 text-sm">
                        <span class="text-slate-600">Sisa hutang <strong class="tabular-nums text-slate-900" x-text="rp(Math.max(sisa, 0))"></strong></span>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                              x-bind:class="status === 'LUNAS' ? 'bg-success-50 text-success-700' : (status === 'SEBAGIAN' ? 'bg-warning-50 text-warning-700' : 'bg-danger-50 text-danger-700')"
                              x-text="status === 'LUNAS' ? 'Lunas' : (status === 'SEBAGIAN' ? 'Dibayar sebagian' : 'Hutang')"></span>
                    </div>
                    <p x-show="sisa < 0" x-cloak class="text-sm text-danger-600">Pembayaran melebihi total pembelian.</p>
                </div>
            </x-ui.card>
        </div>

        <div class="flex flex-wrap justify-end gap-2">
            <x-ui.button :href="route('purchases.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="check" x-bind:disabled="kirim || items.length === 0 || sisa < 0">Simpan pembelian</x-ui.button>
        </div>
    </form>
</x-app-layout>
