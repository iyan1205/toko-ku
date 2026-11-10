{{--
    Kotak cari/scan produk untuk formulir yang memakai x-data="dokumenBarang(...)".
    Enter dengan tepat satu hasil (misal scan barcode) langsung menambah baris.
    Parameter: $label (judul isian).
--}}
<div class="relative border-b border-slate-200 p-5" x-on:click.outside="buka = false">
    <label for="cari-produk" class="block text-sm font-medium text-slate-700">{{ $label ?? 'Tambah produk' }}</label>
    <div class="relative mt-1">
        <x-ui.icon name="magnifying-glass" variant="m" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
        <input id="cari-produk" x-ref="cari" type="text" x-model="q" autocomplete="off"
               placeholder="Ketik nama atau SKU, atau scan barcode lalu Enter"
               x-on:input.debounce.250ms="cari()" x-on:focus="buka = hasil.length > 0"
               x-on:keydown.enter.prevent="enter()" x-on:keydown.escape="buka = false"
               class="block w-full rounded-lg border-slate-300 pl-9 text-sm shadow-sm placeholder:text-slate-400 focus:border-primary-500 focus:ring-primary-500">
    </div>
    <ul x-show="buka" x-cloak class="absolute left-5 right-5 z-20 mt-1 max-h-72 overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
        <template x-for="p in hasil" :key="p.id">
            <li>
                <button type="button" x-on:click="tambah(p)"
                        class="flex w-full items-center justify-between gap-3 px-4 py-2 text-left text-sm hover:bg-primary-50 focus:bg-primary-50 focus:outline-none">
                    <span><span class="font-medium text-slate-900" x-text="p.name"></span> <span class="text-xs text-slate-500" x-text="p.sku"></span></span>
                    <span class="whitespace-nowrap text-xs text-slate-500">stok <span x-text="jumlah(p.stock)"></span> <span x-text="p.unit"></span></span>
                </button>
            </li>
        </template>
        <li x-show="hasil.length === 0 && !memuat" class="px-4 py-3 text-sm text-slate-500">Produk aktif tidak ditemukan.</li>
    </ul>
</div>
