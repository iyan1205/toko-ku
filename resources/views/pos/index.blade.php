@php
    $awal = [
        'urlCariProduk' => $urlCariProduk,
        'urlCariPelanggan' => $urlCariPelanggan,
        'urlTahan' => $urlTahan,
        'urlDaftarTahan' => $urlDaftarTahan,
        'urlSimpanJual' => $urlSimpanJual,
        'urlPanggilTahanTemplate' => $urlPanggilTahan,
        'urlHapusTahanTemplate' => $urlHapusTahan,
        'csrf' => csrf_token(),
        'stokMinus' => $stokMinus,
    ];
@endphp

<x-app-layout>
    <x-slot name="header">Kasir</x-slot>

    <div x-data="kasir(@js($awal))" x-init="init()" x-on:keydown.window="shortcut($event)" class="grid gap-4 lg:grid-cols-5">

        {{-- ===== Kiri: pencarian & daftar produk ===== --}}
        <div class="space-y-3 lg:col-span-3">
            <div class="rounded-lg border border-stone-200 bg-white p-4">
                <div class="flex items-center justify-between gap-2">
                    <label for="cari-produk" class="sr-only">Cari produk</label>
                    <input id="cari-produk" x-ref="cariProduk" type="text" x-model="q" autocomplete="off"
                           placeholder="Cari nama/SKU atau scan barcode (F2)"
                           @input.debounce.200ms="cariProduk()" @keydown.enter.prevent="enterProduk()"
                           class="block w-full rounded-md border-stone-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600">
                    <div class="text-right">
                        <button type="button" @click="bukaTahan()" class="relative whitespace-nowrap rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-700 hover:bg-stone-50">
                            Ditahan (F9)
                            <span x-show="daftarTahan.length > 0" x-cloak x-text="daftarTahan.length"
                                  class="absolute -right-2 -top-2 flex h-5 w-5 items-center justify-center rounded-full bg-amber-500 text-xs font-medium text-white"></span>
                        </button>
                        <p class="mt-1 text-xs text-stone-400">{{ $tahanTersisa }} slot tersisa</p>
                    </div>
                </div>
                <p x-show="memuatProduk" x-cloak class="mt-2 text-xs text-stone-400">Mencari…</p>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                <template x-for="p in hasilProduk" :key="p.id">
                    <button type="button" @click="tambah(p)" :disabled="!stokMinus && p.stock <= 0"
                            class="flex flex-col rounded-lg border border-stone-200 bg-white p-3 text-left hover:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600 disabled:cursor-not-allowed disabled:opacity-50">
                        <span class="text-sm font-medium text-stone-900" x-text="p.name"></span>
                        <span class="text-xs text-stone-500" x-text="p.sku"></span>
                        <span class="mt-2 text-sm font-semibold text-teal-700" x-text="rp(hargaBersih(p))"></span>
                        <span class="mt-1 text-xs" :class="p.stock <= 0 ? 'text-red-600' : 'text-stone-500'">
                            Stok <span x-text="angkaFmt(p.stock)"></span> <span x-text="p.unit"></span>
                        </span>
                    </button>
                </template>
                <p x-show="!memuatProduk && hasilProduk.length === 0" x-cloak class="col-span-full py-10 text-center text-sm text-stone-500">
                    <span x-show="q.trim() === ''">Ketik nama, SKU, atau scan barcode untuk mencari produk.</span>
                    <span x-show="q.trim() !== ''">Produk aktif tidak ditemukan.</span>
                </p>
            </div>
        </div>

        {{-- ===== Kanan: keranjang & pembayaran ===== --}}
        <form method="POST" :action="urlSimpanJual" @submit="siapSubmit" class="flex flex-col rounded-lg border border-stone-200 bg-white lg:col-span-2">
            @csrf
            <template x-for="(it, i) in items" :key="it.product_id">
                <span>
                    <input type="hidden" :name="'items[' + i + '][product_id]'" :value="it.product_id">
                    <input type="hidden" :name="'items[' + i + '][quantity]'" :value="it.quantity">
                    <input type="hidden" :name="'items[' + i + '][discount]'" :value="it.discount || 0">
                </span>
            </template>
            <input type="hidden" name="customer_id" :value="pelanggan ? pelanggan.id : ''">
            <input type="hidden" name="discount" :value="diskonTrans || 0">
            <input type="hidden" name="payment_method" :value="metode">
            <input type="hidden" name="paid_amount" :value="metode === 'cash' ? diterima : (metode === 'receivable' ? (dp || 0) : '')">
            <input type="hidden" name="dp_method" :value="metode === 'receivable' ? dpMetode : ''">
            <input type="hidden" name="held_id" :value="heldId ?? ''">

            <div class="flex-1 divide-y divide-stone-100 overflow-y-auto" style="max-height: 46vh">
                <template x-for="(it, i) in items" :key="'baris-' + it.product_id">
                    <div class="flex items-start gap-2 px-4 py-2.5">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-stone-900" x-text="it.name"></p>
                            <p class="text-xs text-stone-500" x-text="rp(it.unit_price) + ' / ' + it.unit"></p>
                        </div>
                        <div class="flex items-center gap-1">
                            <button type="button" @click="ubahJumlah(i, -1)" class="h-7 w-7 rounded border border-stone-300 text-stone-600 hover:bg-stone-50">−</button>
                            <input type="number" min="0.001" step="0.001" x-model.number="it.quantity" @change="batasiJumlah(i)"
                                   :aria-label="'Jumlah ' + it.name" class="w-16 rounded-md border-stone-300 text-center text-sm focus:border-teal-600 focus:ring-teal-600">
                            <button type="button" @click="ubahJumlah(i, 1)" class="h-7 w-7 rounded border border-stone-300 text-stone-600 hover:bg-stone-50">+</button>
                        </div>
                        <p class="w-24 shrink-0 text-right text-sm font-medium text-stone-900" x-text="rp(baris(it))"></p>
                        <button type="button" @click="hapus(i)" class="text-lg leading-none text-red-600 hover:text-red-800" :aria-label="'Hapus ' + it.name">&times;</button>
                    </div>
                </template>
                <p x-show="items.length === 0" class="px-4 py-10 text-center text-sm text-stone-500">Keranjang kosong. Cari produk di sebelah kiri.</p>
            </div>

            <div class="space-y-3 border-t border-stone-200 p-4">
                <div class="relative" @click.outside="bukaPelanggan = false">
                    <label for="cari-pelanggan" class="block text-xs font-medium text-stone-500">Pelanggan</label>
                    <input id="cari-pelanggan" type="text" x-model="qPelanggan" autocomplete="off"
                           :placeholder="pelanggan ? pelanggan.name : 'Umum'"
                           @input.debounce.200ms="cariPelanggan()" @focus="cariPelanggan()"
                           class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                    <ul x-show="bukaPelanggan" x-cloak class="absolute left-0 right-0 z-20 mt-1 max-h-48 overflow-auto rounded-md border border-stone-200 bg-white shadow-lg">
                        <template x-for="c in hasilPelanggan" :key="c.id">
                            <li>
                                <button type="button" @click="pilihPelanggan(c)" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-teal-50">
                                    <span x-text="c.name"></span>
                                    <span x-show="c.receivable_balance > 0" class="text-xs text-red-600" x-text="rp(c.receivable_balance)"></span>
                                </button>
                            </li>
                        </template>
                    </ul>
                    <p x-show="pelanggan && pelanggan.receivable_balance > 0" x-cloak class="mt-1 text-xs text-red-600" x-text="'Piutang berjalan: ' + rp(pelanggan ? pelanggan.receivable_balance : 0)"></p>
                </div>

                <div>
                    <label for="diskon-trans" class="block text-xs font-medium text-stone-500">Diskon transaksi (Rp) · F8</label>
                    <input id="diskon-trans" x-ref="diskon" type="number" min="0" step="0.01" x-model.number="diskonTrans"
                           class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                </div>

                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-600">Subtotal</dt><dd x-text="rp(subtotal)"></dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Diskon</dt><dd class="text-red-700" x-text="'- ' + rp(angka(diskonTrans))"></dd></div>
                    <div class="flex justify-between text-base font-semibold text-stone-900"><dt>Total</dt><dd x-text="rp(total)"></dd></div>
                </dl>

                <div>
                    <label for="metode" class="block text-xs font-medium text-stone-500">Metode pembayaran</label>
                    <select id="metode" x-ref="metode" x-model="metode" class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                        @foreach ($metode as $m)
                            <option value="{{ $m->value }}">{{ $m->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <template x-if="metode === 'cash'">
                    <div>
                        <label for="diterima" class="block text-xs font-medium text-stone-500">Bayar (Rp) · F4</label>
                        <input id="diterima" x-ref="bayar" type="number" min="0" step="0.01" x-model.number="diterima"
                               class="mt-1 block w-full rounded-md border-stone-300 text-lg font-medium focus:border-teal-600 focus:ring-teal-600">
                        <div class="mt-1 flex flex-wrap gap-1">
                            <template x-for="cepat in uangCepat()" :key="cepat">
                                <button type="button" @click="diterima = cepat" class="rounded border border-stone-300 px-2 py-1 text-xs text-stone-600 hover:bg-stone-50" x-text="rp(cepat)"></button>
                            </template>
                        </div>
                        <p class="mt-1 text-sm" :class="kembalian >= 0 ? 'text-stone-600' : 'text-red-600'">
                            Kembalian: <span class="font-semibold" x-text="rp(Math.max(kembalian, 0))"></span>
                        </p>
                    </div>
                </template>

                <template x-if="metode === 'receivable'">
                    <div class="space-y-2 rounded-md bg-amber-50 p-3">
                        <p class="text-xs text-amber-800">Piutang membutuhkan pelanggan terdaftar (bukan Umum).</p>
                        <div>
                            <label for="dp" class="block text-xs font-medium text-stone-600">Bayar di muka / DP (Rp) · opsional</label>
                            <input id="dp" type="number" min="0" step="0.01" x-model.number="dp"
                                   class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                        </div>
                        <div x-show="angka(dp) > 0" x-cloak>
                            <label for="dp-metode" class="block text-xs font-medium text-stone-600">Metode DP</label>
                            <select id="dp-metode" x-model="dpMetode" class="mt-1 block w-full rounded-md border-stone-300 text-sm focus:border-teal-600 focus:ring-teal-600">
                                @foreach (\App\Enums\PaymentMethod::untukPembelian() as $m)
                                    <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <p class="text-sm text-amber-900">Sisa jadi piutang: <strong x-text="rp(Math.max(total - angka(dp), 0))"></strong></p>
                    </div>
                </template>

                <template x-if="metode !== 'cash' && metode !== 'receivable'">
                    <p class="rounded-md bg-stone-50 px-3 py-2 text-sm text-stone-600">Dibayar penuh via <span x-text="labelMetode()"></span>, tanpa kembalian.</p>
                </template>

                <p x-show="pesan" x-cloak class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700" x-text="pesan"></p>

                <div class="flex gap-2">
                    <button type="button" @click="tahan()" :disabled="items.length === 0 || memproses"
                            class="flex-1 rounded-md border border-stone-300 px-4 py-2.5 text-sm font-medium text-stone-700 hover:bg-stone-50 disabled:opacity-50">
                        Tahan (F9)
                    </button>
                    <button type="submit" :disabled="!bolehBayar || memproses"
                            class="flex-[2] rounded-md bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        <span x-text="memproses ? 'Memproses…' : 'Bayar (F10)'"></span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    {{-- ===== Modal: transaksi ditahan ===== --}}
    <div x-show="modalTahan" x-cloak x-on:keydown.escape.window="modalTahan = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 p-4" role="dialog" aria-modal="true" aria-labelledby="judul-tahan"
         x-data x-ref="rootTahan">
        <div @click.outside="modalTahan = false" class="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
            <h2 id="judul-tahan" class="text-lg font-semibold text-stone-900">Transaksi ditahan</h2>
            <div class="mt-4 max-h-80 divide-y divide-stone-100 overflow-y-auto">
                <template x-for="t in daftarTahan" :key="t.id">
                    <div class="flex items-center justify-between gap-2 py-2.5">
                        <div>
                            <p class="text-sm font-medium text-stone-900" x-text="t.label"></p>
                            <p class="text-xs text-stone-500" x-text="t.customer + ' · ' + t.items + ' produk · ' + t.created_at"></p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" @click="panggilTahan(t.id)" class="rounded-md bg-teal-700 px-3 py-1.5 text-sm text-white hover:bg-teal-800">Panggil</button>
                            <button type="button" @click="hapusTahan(t.id)" class="rounded-md border border-stone-300 px-3 py-1.5 text-sm text-stone-600 hover:bg-stone-50">Hapus</button>
                        </div>
                    </div>
                </template>
                <p x-show="daftarTahan.length === 0" class="py-8 text-center text-sm text-stone-500">Belum ada transaksi yang ditahan.</p>
            </div>
            <div class="mt-4 flex justify-end">
                <button type="button" @click="modalTahan = false" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Tutup</button>
            </div>
        </div>
    </div>

    @verbatim
    <script>
        // Semua angka di layar hanya pratinjau. Server (SaleService) selalu menghitung ulang dari harga
        // database dan menolak bila stok tidak cukup, sehingga keranjang ini tidak pernah "dipercaya mentah".
        function kasir(awal) {
            return {
                ...awal,
                q: '', hasilProduk: [], memuatProduk: false,
                items: [], diskonTrans: 0,
                qPelanggan: '', hasilPelanggan: [], bukaPelanggan: false, pelanggan: null,
                metode: 'cash', diterima: 0, dp: 0, dpMetode: 'cash',
                daftarTahan: [], modalTahan: false, heldId: null,
                memproses: false, pesan: '',

                init() {
                    this.muatTahan();
                    this.$nextTick(() => this.$refs.cariProduk.focus());
                },

                angka(v) { return Number(v) || 0; },
                bulat(v) { return Math.round((this.angka(v)) * 100) / 100; },
                rp(v) { return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(this.angka(v)); },
                angkaFmt(v) { return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(this.angka(v)); },
                hargaBersih(p) { return this.bulat(p.selling_price * (1 - (p.discount_percent || 0) / 100)); },
                labelMetode() {
                    const opt = this.$refs.metode?.selectedOptions?.[0];
                    return opt ? opt.text : this.metode;
                },

                baris(it) { return this.bulat(this.angka(it.quantity) * this.angka(it.unit_price) - this.angka(it.discount)); },
                get subtotal() { return this.bulat(this.items.reduce((s, it) => s + this.baris(it), 0)); },
                get total() { return Math.max(0, this.bulat(this.subtotal - this.angka(this.diskonTrans))); },
                get kembalian() { return this.bulat(this.angka(this.diterima) - this.total); },

                get bolehBayar() {
                    if (this.items.length === 0) return false;
                    if (this.metode === 'cash') return this.angka(this.diterima) >= this.total;
                    if (this.metode === 'receivable') return this.pelanggan && !this.pelanggan.is_default && this.angka(this.dp) < this.total;
                    return true;
                },

                async ambil(url) {
                    const res = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                    if (!res.ok) throw new Error('gagal');
                    return res.json();
                },

                async cariProduk() {
                    const kata = this.q.trim();
                    if (kata === '') { this.hasilProduk = []; return; }
                    this.memuatProduk = true;
                    try { this.hasilProduk = await this.ambil(this.urlCariProduk + '?q=' + encodeURIComponent(kata)); }
                    catch (e) { this.hasilProduk = []; }
                    this.memuatProduk = false;
                },
                async enterProduk() {
                    await this.cariProduk();
                    if (this.hasilProduk.length === 1) this.tambah(this.hasilProduk[0]);
                },
                tambah(p) {
                    if (!this.stokMinus && p.stock <= 0) return;
                    const ada = this.items.find(it => it.product_id === p.id);
                    if (ada) { ada.quantity = this.angka(ada.quantity) + 1; }
                    else {
                        this.items.push({
                            product_id: p.id, sku: p.sku, name: p.name, unit: p.unit,
                            quantity: 1, unit_price: this.hargaBersih(p), discount: 0, stock: p.stock,
                        });
                    }
                    this.q = ''; this.hasilProduk = [];
                    this.$nextTick(() => this.$refs.cariProduk.focus());
                },
                ubahJumlah(i, delta) {
                    const baru = this.bulat(this.angka(this.items[i].quantity) + delta);
                    this.items[i].quantity = baru > 0 ? baru : 0.001;
                },
                batasiJumlah(i) { if (this.angka(this.items[i].quantity) <= 0) this.items[i].quantity = 0.001; },
                hapus(i) { this.items.splice(i, 1); },

                async cariPelanggan() {
                    try { this.hasilPelanggan = await this.ambil(this.urlCariPelanggan + '?q=' + encodeURIComponent(this.qPelanggan.trim())); }
                    catch (e) { this.hasilPelanggan = []; }
                    this.bukaPelanggan = true;
                },
                pilihPelanggan(c) { this.pelanggan = c.is_default ? null : c; this.qPelanggan = ''; this.bukaPelanggan = false; },

                uangCepat() {
                    const t = this.total;
                    if (t <= 0) return [];
                    const bulat = Math.ceil(t / 1000) * 1000;
                    return [...new Set([bulat, bulat + 10000, bulat + 50000, bulat + 100000])].slice(0, 4);
                },

                resetKeranjang() {
                    this.items = []; this.diskonTrans = 0; this.pelanggan = null; this.qPelanggan = '';
                    this.metode = 'cash'; this.diterima = 0; this.dp = 0; this.heldId = null; this.pesan = '';
                },

                async tahan() {
                    if (this.items.length === 0) return;
                    this.pesan = '';
                    try {
                        const res = await fetch(this.urlTahan, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                customer_id: this.pelanggan ? this.pelanggan.id : null,
                                discount: this.diskonTrans || 0,
                                items: this.items.map(it => ({ product_id: it.product_id, quantity: it.quantity, discount: it.discount || 0 })),
                            }),
                        });
                        if (!res.ok) { const d = await res.json(); this.pesan = d.message || 'Gagal menahan transaksi.'; return; }
                        this.resetKeranjang();
                        await this.muatTahan();
                        this.$nextTick(() => this.$refs.cariProduk.focus());
                    } catch (e) { this.pesan = 'Gagal menahan transaksi.'; }
                },

                async muatTahan() {
                    try { this.daftarTahan = await this.ambil(this.urlDaftarTahan); } catch (e) { this.daftarTahan = []; }
                },
                bukaTahan() { this.muatTahan(); this.modalTahan = true; },

                async panggilTahan(id) {
                    try {
                        const d = await this.ambil(this.urlPanggilTahanTemplate.replace('__ID__', id));
                        this.items = d.items.map(it => ({
                            product_id: it.product_id, sku: it.sku, name: it.name, unit: it.unit,
                            quantity: it.quantity, unit_price: it.unit_price, discount: it.discount || 0, stock: it.stock,
                        }));
                        this.diskonTrans = d.discount || 0;
                        this.pelanggan = d.customer && !d.customer.is_default ? d.customer : null;
                        this.heldId = id;
                        this.modalTahan = false;
                        if (d.produk_hilang > 0) this.pesan = d.produk_hilang + ' produk pada transaksi ini sudah tidak tersedia dan dilewati.';
                    } catch (e) { this.pesan = 'Gagal memanggil transaksi yang ditahan.'; }
                },

                async hapusTahan(id) {
                    try {
                        await fetch(this.urlHapusTahanTemplate.replace('__ID__', id), {
                            method: 'DELETE',
                            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf },
                            credentials: 'same-origin',
                        });
                        await this.muatTahan();
                    } catch (e) { /* diamkan: daftar akan sinkron saat dibuka lagi */ }
                },

                siapSubmit(e) {
                    if (!this.bolehBayar) { e.preventDefault(); return; }
                    this.memproses = true;
                },

                shortcut(e) {
                    if (e.key === 'F2') { e.preventDefault(); this.$refs.cariProduk.focus(); }
                    else if (e.key === 'F4') { e.preventDefault(); (this.metode === 'cash' ? this.$refs.bayar : this.$refs.metode)?.focus(); }
                    else if (e.key === 'F8') { e.preventDefault(); this.$refs.diskon?.focus(); }
                    else if (e.key === 'F9') { e.preventDefault(); this.tahan(); }
                    else if (e.key === 'Escape') { this.modalTahan = false; }
                },
            };
        }
    </script>
    @endverbatim
</x-app-layout>
