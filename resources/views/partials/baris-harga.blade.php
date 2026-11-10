{{--
    Tabel baris barang berharga (jumlah, harga, diskon baris) untuk formulir x-data="dokumenBarang(...)":
    purchase order dan pembelian langsung. Nama isian: items[i][product_id|quantity|unit_price|discount].
--}}
@php
    $th = 'px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500';
    $input = 'block w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500';
@endphp
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200 text-sm">
        <thead class="bg-slate-50">
            <tr>
                <th class="{{ $th }}">Produk</th>
                <th class="{{ $th }} w-28">Jumlah</th>
                <th class="{{ $th }} w-40">Harga (Rp)</th>
                <th class="{{ $th }} w-36">Diskon baris (Rp)</th>
                <th class="{{ $th }} text-right">Subtotal</th>
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
                    <td class="px-4 py-3"><input type="number" min="0.001" step="0.001" required x-model="it.quantity" :name="'items[' + i + '][quantity]'" :aria-label="'Jumlah ' + it.name" class="{{ $input }}"></td>
                    <td class="px-4 py-3"><input type="number" min="0" step="0.01" required x-model="it.unit_price" :name="'items[' + i + '][unit_price]'" :aria-label="'Harga ' + it.name" class="{{ $input }}"></td>
                    <td class="px-4 py-3"><input type="number" min="0" step="0.01" x-model="it.discount" :name="'items[' + i + '][discount]'" :aria-label="'Diskon ' + it.name" class="{{ $input }}"></td>
                    <td class="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-slate-900" x-text="rp(baris(it))"></td>
                    <td class="px-2 py-3 text-center">
                        <button type="button" x-on:click="hapus(i)" class="rounded p-1 text-slate-400 hover:bg-danger-50 hover:text-danger-600" :aria-label="'Hapus ' + it.name">
                            <x-ui.icon name="x-mark" variant="m" class="h-4 w-4" />
                        </button>
                    </td>
                </tr>
            </template>
            <tr x-show="items.length === 0">
                <td colspan="6" class="px-4 py-10 text-center text-slate-500">Belum ada produk. Cari atau scan produk di atas.</td>
            </tr>
        </tbody>
    </table>
</div>
