{{--
    Transfer stok baru: pilih gudang asal & tujuan, lalu cari/scan produk. Stok di setiap baris
    = stok di gudang asal (dimuat ulang saat gudang asal diganti). Server menolak bila stok kurang.
--}}
@php
    $awal = [
        'urlCari' => route('stock-transfers.products'),
        'items' => $items,
        'gudang' => (string) $dari,
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Transfer stok baru</x-slot>
    <x-slot name="description">Stok keluar dari gudang asal dan masuk ke gudang tujuan saat disimpan.</x-slot>

    @if ($gudangTujuan->count() < 2)
        <x-ui.alert type="warning" class="mb-6" title="Butuh minimal dua gudang aktif">
            Tambahkan gudang lain di menu Gudang/Lokasi sebelum memindahkan stok.
        </x-ui.alert>
    @endif

    @if ($errors->any())
        <x-ui.alert type="danger" class="mb-6" title="Transfer belum bisa disimpan">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('stock-transfers.store') }}" x-data="dokumenBarang(@js($awal))" x-on:submit="kirim = true" class="grid gap-6">
        @csrf

        <x-ui.card>
            <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.select name="from_warehouse_id" label="Dari gudang" required :options="$gudang->pluck('name', 'id')" :selected="$dari" x-model="gudang" />
                <x-ui.select name="to_warehouse_id" label="Ke gudang" required placeholder="Pilih gudang tujuan"
                             :options="$gudangTujuan->pluck('name', 'id')" :selected="old('to_warehouse_id')" />
                <x-ui.input name="transfer_date" label="Tanggal" type="date" :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" required />
                <x-ui.input name="note" label="Catatan" maxlength="255" placeholder="Opsional, misal: isi ulang rak toko" />
            </div>
        </x-ui.card>

        <x-ui.card :padding="false">
            @include('partials.cari-produk', ['label' => 'Tambah produk'])

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Produk</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Stok di gudang asal</th>
                            <th class="w-40 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Jumlah dipindah</th>
                            <th class="w-12 px-2 py-3"><span class="sr-only">Hapus</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(it, i) in items" :key="it.product_id">
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-slate-900" x-text="it.name"></p>
                                    <p class="text-xs text-slate-500"><span x-text="it.sku"></span> · <span x-text="it.unit"></span></p>
                                    <input type="hidden" :name="'items[' + i + '][product_id]'" :value="it.product_id">
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums" :class="kurang(it) ? 'font-semibold text-danger-600' : 'text-slate-600'"
                                    x-text="it.stock === undefined ? '…' : jumlah(it.stock)"></td>
                                <td class="px-4 py-3">
                                    <input type="number" min="0.001" step="0.001" required x-model="it.quantity" :name="'items[' + i + '][quantity]'"
                                           :aria-label="'Jumlah ' + it.name"
                                           class="block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <p x-show="kurang(it)" x-cloak class="mt-1 text-xs text-danger-600">Melebihi stok gudang asal.</p>
                                </td>
                                <td class="px-2 py-3 text-center">
                                    <button type="button" x-on:click="hapus(i)" class="rounded p-1 text-slate-400 hover:bg-danger-50 hover:text-danger-600" :aria-label="'Hapus ' + it.name">
                                        <x-ui.icon name="x-mark" variant="m" class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="items.length === 0">
                            <td colspan="4" class="px-4 py-10 text-center text-slate-500">Belum ada produk. Cari atau scan produk di atas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <div class="flex justify-end gap-2">
            <x-ui.button :href="route('stock-transfers.index')" variant="secondary">Batal</x-ui.button>
            <x-ui.button type="submit" icon="arrows-right-left" x-bind:disabled="kirim || items.length === 0">Simpan transfer</x-ui.button>
        </div>
    </form>
</x-app-layout>
