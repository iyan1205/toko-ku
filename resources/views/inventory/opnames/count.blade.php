{{-- Hitung stok opname dari HP: scan barcode (kamera/scanner) atau ketik kode; jumlah fisik tersimpan langsung. --}}
@use('App\Support\Format')
@php
    $awal = [
        'urlScan' => route('stock-opnames.scan', $opname),
        'items' => $opname->items->map(fn ($i) => [
            'product_id' => $i->product_id, 'name' => $i->product?->name, 'sku' => $i->product?->sku, 'unit' => $i->product?->unit?->name,
            'physical' => (float) $i->physical_stock, 'system' => (float) $i->system_stock, 'difference' => (float) $i->difference,
        ])->values(),
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Hitung {{ $opname->number }}</x-slot>
    <x-slot name="description">{{ $opname->warehouse->name }} · {{ Format::tanggal($opname->opname_date) }}{{ $opname->freeze_stock ? ' · stok gudang dibekukan' : '' }}</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="pencil-square" :href="route('stock-opnames.edit', $opname)">Lengkapi alasan & selesaikan</x-ui.button>
    </x-slot>

    <div class="mx-auto grid max-w-2xl gap-4" x-data="opnameScan(@js($awal))">
        <x-ui.card>
            <form class="grid gap-3" x-on:submit.prevent="scan()">
                <div class="flex gap-2">
                    <input x-ref="kode" x-model="kode" type="text" inputmode="text" autocomplete="off" autofocus placeholder="Scan atau ketik barcode / SKU"
                           aria-label="Barcode atau SKU" class="block w-full rounded-lg border-slate-300 py-3 text-base shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <input x-model="jumlah" type="number" min="0.001" step="any" inputmode="decimal" aria-label="Jumlah per scan"
                           class="block w-20 rounded-lg border-slate-300 py-3 text-center text-base shadow-sm focus:border-primary-500 focus:ring-primary-500">
                </div>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button type="submit" icon="plus" class="flex-1 justify-center py-3" x-bind:disabled="sibuk">Hitung</x-ui.button>
                    <template x-if="bisaKamera">
                        <x-ui.button type="button" variant="secondary" icon="camera" class="flex-1 justify-center py-3" x-on:click="kamera ? tutupKamera() : bukaKamera()">
                            <span x-text="kamera ? 'Tutup kamera' : 'Scan pakai kamera'"></span>
                        </x-ui.button>
                    </template>
                </div>
                <video x-ref="video" x-show="kamera" x-cloak playsinline muted class="w-full rounded-lg bg-black"></video>
                <p class="text-sm font-medium text-success-700" x-show="pesan && ! galat" x-text="pesan" aria-live="polite"></p>
                <p class="text-sm font-medium text-danger-600" x-show="galat" x-text="galat" x-cloak aria-live="assertive"></p>
            </form>
        </x-ui.card>

        <x-ui.card :padding="false">
            <x-slot:title><span x-text="items.length + ' produk dihitung'"></span></x-slot:title>
            <ul class="divide-y divide-slate-100">
                <template x-for="i in items" x-bind:key="i.product_id">
                    <li class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-slate-900" x-text="i.name"></p>
                            <p class="text-xs text-slate-500">
                                Sistem <span x-text="i.system"></span> ·
                                selisih <span x-bind:class="i.difference < 0 ? 'text-danger-600' : (i.difference > 0 ? 'text-success-700' : '')" x-text="(i.difference > 0 ? '+' : '') + i.difference"></span>
                            </p>
                        </div>
                        <input type="number" min="0" step="any" inputmode="decimal" x-bind:value="i.physical" x-on:change="ubah(i, $event.target.value)"
                               x-bind:aria-label="'Jumlah fisik ' + i.name" class="block w-24 rounded-lg border-slate-300 text-right text-sm shadow-sm">
                    </li>
                </template>
                <li x-show="items.length === 0" class="px-4 py-8 text-center text-sm text-slate-500">Belum ada yang dihitung. Scan barang pertama.</li>
            </ul>
        </x-ui.card>
    </div>
</x-app-layout>
