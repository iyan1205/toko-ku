@php
    $awal = [
        'items' => $items,
        'diskon' => old('discount', 0),
        'pajak' => old('tax', 0),
        'biaya' => old('other_cost', 0),
        'bayar' => old('paid_amount', 0),
        'metode' => old('payment_method', 'cash'),
        'urlCari' => route('purchases.products'),
    ];
    $inputClass = 'mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600';
@endphp

<x-app-layout>
    <x-slot name="header">Pembelian baru</x-slot>

    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="alert">
            <p class="font-semibold">Pembelian belum bisa disimpan:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $pesan)
                    <li>{{ $pesan }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('purchases.store') }}" x-data="formPembelian(@js($awal))" @submit="kirim = true"
          class="space-y-6">
        @csrf

        {{-- 1. Data faktur --}}
        <div class="grid gap-4 rounded-lg border border-stone-200 bg-white p-5 sm:grid-cols-2 lg:grid-cols-4">
            <div class="lg:col-span-2">
                <label for="supplier_id" class="block text-sm font-medium text-stone-700">Supplier</label>
                <select id="supplier_id" name="supplier_id" required class="{{ $inputClass }}">
                    <option value="">Pilih supplier</option>
                    @foreach ($suppliers as $s)
                        <option value="{{ $s->id }}" @selected((string) old('supplier_id', $supplierDipilih) === (string) $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
                @if ($suppliers->isEmpty())
                    <p class="mt-1 text-xs text-amber-700">Belum ada supplier. Tambahkan dulu di menu Supplier.</p>
                @endif
            </div>
            <x-isian label="Nomor faktur supplier" name="supplier_invoice_number" maxlength="50" />
            <x-isian label="Tanggal pembelian" name="purchase_date" type="date" :value="now()->format('Y-m-d')" :wajib="true" :max="now()->format('Y-m-d')" />
        </div>

        {{-- 2. Pilih produk --}}
        <div class="rounded-lg border border-stone-200 bg-white">
            <div class="relative border-b border-stone-200 p-5" @click.outside="buka = false">
                <label for="cari-produk" class="block text-sm font-medium text-stone-700">Tambah produk</label>
                <input id="cari-produk" type="text" x-model="q" autocomplete="off" placeholder="Ketik nama atau SKU, atau scan barcode lalu Enter"
                       @input.debounce.250ms="cari()" @focus="buka = hasil.length > 0" @keydown.enter.prevent="enter()" @keydown.escape="buka = false"
                       class="{{ $inputClass }}">
                <ul x-show="buka" x-cloak class="absolute left-5 right-5 z-20 mt-1 max-h-72 overflow-auto rounded-md border border-stone-200 bg-white shadow-lg">
                    <template x-for="p in hasil" :key="p.id">
                        <li>
                            <button type="button" @click="tambah(p)" class="flex w-full items-center justify-between gap-3 px-4 py-2 text-left text-sm hover:bg-teal-50 focus:bg-teal-50 focus:outline-none">
                                <span><span class="font-medium text-stone-900" x-text="p.name"></span> <span class="text-xs text-stone-500" x-text="p.sku"></span></span>
                                <span class="whitespace-nowrap text-xs text-stone-500">stok <span x-text="p.stock"></span> <span x-text="p.unit"></span></span>
                            </button>
                        </li>
                    </template>
                    <li x-show="hasil.length === 0 && !memuat" class="px-4 py-3 text-sm text-stone-500">Produk aktif tidak ditemukan.</li>
                </ul>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-stone-50 text-left text-stone-600">
                        <tr>
                            <th class="px-4 py-3 font-medium">Produk</th>
                            <th class="w-28 px-4 py-3 font-medium">Jumlah</th>
                            <th class="w-40 px-4 py-3 font-medium">Harga beli (Rp)</th>
                            <th class="w-36 px-4 py-3 font-medium">Diskon baris (Rp)</th>
                            <th class="px-4 py-3 text-right font-medium">Subtotal</th>
                            <th class="w-10 px-2 py-3"><span class="sr-only">Hapus</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        <template x-for="(it, i) in items" :key="it.product_id">
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-stone-900" x-text="it.name"></p>
                                    <p class="text-xs text-stone-500"><span x-text="it.sku"></span> · <span x-text="it.unit"></span></p>
                                    <input type="hidden" :name="'items[' + i + '][product_id]'" :value="it.product_id">
                                </td>
                                <td class="px-4 py-3"><input type="number" min="0.001" step="0.001" required x-model="it.quantity" :name="'items[' + i + '][quantity]'" :aria-label="'Jumlah ' + it.name" class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600"></td>
                                <td class="px-4 py-3"><input type="number" min="0" step="0.01" required x-model="it.unit_price" :name="'items[' + i + '][unit_price]'" :aria-label="'Harga beli ' + it.name" class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600"></td>
                                <td class="px-4 py-3"><input type="number" min="0" step="0.01" x-model="it.discount" :name="'items[' + i + '][discount]'" :aria-label="'Diskon ' + it.name" class="w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600"></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-stone-900" x-text="rp(baris(it))"></td>
                                <td class="px-2 py-3 text-center"><button type="button" @click="hapus(i)" class="text-lg leading-none text-red-600 hover:text-red-800" :aria-label="'Hapus ' + it.name">&times;</button></td>
                            </tr>
                        </template>
                        <tr x-show="items.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-stone-500">Belum ada produk. Cari produk di atas untuk menambahkannya.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- 3. Total dan pembayaran --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-4 rounded-lg border border-stone-200 bg-white p-5">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="discount" class="block text-sm font-medium text-stone-700">Diskon (Rp)</label>
                        <input id="discount" name="discount" type="number" min="0" step="0.01" x-model="diskon" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label for="tax" class="block text-sm font-medium text-stone-700">Pajak (Rp)</label>
                        <input id="tax" name="tax" type="number" min="0" step="0.01" x-model="pajak" class="{{ $inputClass }}">
                    </div>
                    <div>
                        <label for="other_cost" class="block text-sm font-medium text-stone-700">Biaya lain (Rp)</label>
                        <input id="other_cost" name="other_cost" type="number" min="0" step="0.01" x-model="biaya" class="{{ $inputClass }}">
                    </div>
                </div>
                <div>
                    <label for="note" class="block text-sm font-medium text-stone-700">Catatan <span class="font-normal text-stone-400">(opsional)</span></label>
                    <textarea id="note" name="note" rows="2" maxlength="1000" class="{{ $inputClass }}">{{ old('note') }}</textarea>
                </div>
                <label class="flex items-start gap-2 text-sm text-stone-700">
                    <input type="checkbox" name="update_cost" value="1" @checked(old('update_cost', true)) class="mt-0.5 rounded border-stone-300 text-teal-700 focus:ring-teal-600">
                    <span>Perbarui harga beli produk dengan harga pembelian ini
                        <span class="block text-xs text-stone-500">Memakai harga bersih per unit (setelah diskon baris). Menjadi dasar harga modal penjualan berikutnya; riwayat lama tidak berubah.</span></span>
                </label>
            </div>

            <div class="space-y-4 rounded-lg border border-stone-200 bg-white p-5">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-600">Subtotal</dt><dd class="font-medium" x-text="rp(subtotal)"></dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Diskon</dt><dd class="font-medium text-red-700" x-text="'- ' + rp(diskon)"></dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Pajak</dt><dd class="font-medium" x-text="rp(pajak)"></dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Biaya lain</dt><dd class="font-medium" x-text="rp(biaya)"></dd></div>
                    <div class="flex justify-between border-t border-stone-200 pt-2 text-base"><dt class="font-semibold text-stone-900">Grand total</dt><dd class="font-semibold text-stone-900" x-text="rp(total)"></dd></div>
                </dl>

                <div class="border-t border-stone-200 pt-4">
                    <div class="flex items-end gap-2">
                        <div class="flex-1">
                            <label for="paid_amount" class="block text-sm font-medium text-stone-700">Dibayar sekarang (Rp)</label>
                            <input id="paid_amount" name="paid_amount" type="number" min="0" step="0.01" x-model="bayar" class="{{ $inputClass }}">
                        </div>
                        <button type="button" @click="bayar = total" class="rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">Bayar lunas</button>
                    </div>

                    <div class="mt-3" x-show="angka(bayar) > 0" x-cloak>
                        <label for="payment_method" class="block text-sm font-medium text-stone-700">Metode pembayaran</label>
                        <select id="payment_method" name="payment_method" x-model="metode" :disabled="angka(bayar) <= 0" class="{{ $inputClass }}">
                            @foreach ($metode as $m)
                                <option value="{{ $m->value }}">{{ $m->label() }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mt-3">
                        <label for="due_date" class="block text-sm font-medium text-stone-700">
                            Jatuh tempo <span class="font-normal text-stone-400" x-show="sisa <= 0">(opsional)</span><span class="font-normal text-red-600" x-show="sisa > 0" x-cloak>(wajib, masih ada hutang)</span>
                        </label>
                        <input id="due_date" name="due_date" type="date" value="{{ old('due_date') }}" class="{{ $inputClass }}">
                    </div>

                    <div class="mt-4 flex items-center justify-between rounded-md bg-stone-50 px-4 py-3 text-sm">
                        <span class="text-stone-600">Sisa hutang <strong class="text-stone-900" x-text="rp(Math.max(sisa, 0))"></strong></span>
                        <span class="rounded-full px-2.5 py-0.5 text-xs font-medium"
                              :class="status === 'LUNAS' ? 'bg-teal-50 text-teal-800' : (status === 'SEBAGIAN' ? 'bg-amber-50 text-amber-800' : 'bg-red-50 text-red-800')"
                              x-text="status"></span>
                    </div>
                    <p x-show="sisa < 0" x-cloak class="mt-2 text-sm text-red-600">Pembayaran melebihi grand total.</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <a href="{{ route('purchases.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit" :disabled="kirim || items.length === 0 || sisa < 0"
                    class="rounded-md bg-teal-700 px-5 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                Simpan pembelian
            </button>
        </div>
        <p class="text-right text-xs text-stone-500">Pembelian yang sudah disimpan tidak bisa diubah. Bila salah, batalkan lalu catat ulang.</p>
    </form>

    @verbatim
    <script>
        // Angka di layar hanya pratinjau. Server menghitung ulang semuanya dengan bilangan bulat.
        function formPembelian(awal) {
            return {
                items: awal.items, q: '', hasil: [], buka: false, memuat: false, kirim: false,
                diskon: awal.diskon, pajak: awal.pajak, biaya: awal.biaya, bayar: awal.bayar, metode: awal.metode,
                angka(v) { return Number(v) || 0; },
                bulat(v) { return Math.round((Number(v) || 0) * 100) / 100; },
                rp(v) { return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(this.angka(v)); },
                baris(it) { return this.bulat(this.bulat(this.angka(it.quantity) * this.angka(it.unit_price)) - this.angka(it.discount)); },
                get subtotal() { return this.bulat(this.items.reduce((s, it) => s + this.baris(it), 0)); },
                get total() { return this.bulat(this.subtotal - this.angka(this.diskon) + this.angka(this.pajak) + this.angka(this.biaya)); },
                get sisa() { return this.bulat(this.total - this.angka(this.bayar)); },
                get status() { return this.sisa <= 0 ? 'LUNAS' : (this.angka(this.bayar) > 0 ? 'SEBAGIAN' : 'HUTANG'); },
                async cari() {
                    const kata = this.q.trim();
                    if (kata === '') { this.hasil = []; this.buka = false; return; }
                    this.memuat = true;
                    try {
                        const res = await fetch(awal.urlCari + '?q=' + encodeURIComponent(kata), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        this.hasil = res.ok ? await res.json() : [];
                    } catch (e) { this.hasil = []; }
                    this.memuat = false;
                    this.buka = true;
                },
                // Scanner mengetik barcode lalu Enter: bila tepat satu produk cocok, langsung ditambahkan.
                async enter() {
                    await this.cari();
                    if (this.hasil.length === 1) { this.tambah(this.hasil[0]); }
                },
                tambah(p) {
                    const ada = this.items.find(it => it.product_id === p.id);
                    if (ada) { ada.quantity = this.angka(ada.quantity) + 1; }
                    else { this.items.push({ product_id: p.id, sku: p.sku, name: p.name, unit: p.unit, quantity: 1, unit_price: p.purchase_price, discount: 0 }); }
                    this.q = ''; this.hasil = []; this.buka = false;
                    this.$nextTick(() => document.getElementById('cari-produk').focus());
                },
                hapus(i) { this.items.splice(i, 1); },
            };
        }
    </script>
    @endverbatim
</x-app-layout>
