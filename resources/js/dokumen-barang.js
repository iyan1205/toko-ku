/**
 * Formulir dokumen berisi baris barang: pembelian, purchase order, transfer stok.
 * Alpine: x-data="dokumenBarang(awal)".
 *
 * awal = {
 *   urlCari,            // endpoint App\Services\Inventory\ProductSearch (?q=, ?gudang=, ?ids=)
 *   items: [],          // baris awal (old input / dokumen draft)
 *   gudang: '',         // bila diisi, stok di hasil cari = stok gudang ini
 *   diskon, pajak, biaya, bayar, metode   // hanya formulir yang punya harga
 * }
 *
 * Angka di layar hanya pratinjau. Server menghitung ulang semuanya dengan bilangan bulat.
 */
export default (awal) => ({
    items: awal.items ?? [],
    q: '',
    hasil: [],
    buka: false,
    memuat: false,
    kirim: false,
    gudang: awal.gudang ?? '',
    diskon: awal.diskon ?? 0,
    pajak: awal.pajak ?? 0,
    biaya: awal.biaya ?? 0,
    bayar: awal.bayar ?? 0,
    metode: awal.metode ?? '',

    init() {
        // Ganti gudang asal: stok di baris yang sudah dipilih dimuat ulang.
        this.$watch('gudang', () => this.muatStok());
    },

    angka(v) {
        return Number(v) || 0;
    },
    bulat(v) {
        return Math.round((Number(v) || 0) * 100) / 100;
    },
    rp(v) {
        return 'Rp ' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(this.angka(v));
    },
    jumlah(v) {
        return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(this.angka(v));
    },

    kotor(it) {
        return this.bulat(this.angka(it.quantity) * this.angka(it.unit_price));
    },
    baris(it) {
        return this.bulat(this.kotor(it) - this.angka(it.discount));
    },
    get subtotal() {
        return this.bulat(this.items.reduce((s, it) => s + this.kotor(it), 0));
    },
    get diskonBaris() {
        return this.bulat(this.items.reduce((s, it) => s + this.angka(it.discount), 0));
    },
    get total() {
        return this.bulat(this.subtotal - this.diskonBaris - this.angka(this.diskon) + this.angka(this.pajak) + this.angka(this.biaya));
    },
    get sisa() {
        return this.bulat(this.total - this.angka(this.bayar));
    },
    get status() {
        return this.sisa <= 0 ? 'LUNAS' : (this.angka(this.bayar) > 0 ? 'SEBAGIAN' : 'HUTANG');
    },
    kurang(it) {
        return it.stock !== undefined && this.angka(it.quantity) > this.angka(it.stock);
    },

    url(params) {
        const query = new URLSearchParams(params);
        if (this.gudang) {
            query.set('gudang', this.gudang);
        }
        return awal.urlCari + '?' + query.toString();
    },
    async ambil(params) {
        try {
            const res = await fetch(this.url(params), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            return res.ok ? await res.json() : [];
        } catch (e) {
            return [];
        }
    },
    async cari() {
        const kata = this.q.trim();
        if (kata === '') {
            this.hasil = [];
            this.buka = false;
            return;
        }
        this.memuat = true;
        this.hasil = await this.ambil({ q: kata });
        this.memuat = false;
        this.buka = true;
    },
    // Scanner mengetik barcode lalu Enter: bila tepat satu produk cocok, langsung ditambahkan.
    async enter() {
        await this.cari();
        if (this.hasil.length === 1) {
            this.tambah(this.hasil[0]);
        }
    },
    async muatStok() {
        if (this.items.length === 0) {
            return;
        }
        const data = await this.ambil({ ids: this.items.map((it) => it.product_id).join(',') });
        data.forEach((p) => {
            const it = this.items.find((x) => x.product_id === p.id);
            if (it) {
                it.stock = p.stock;
            }
        });
    },
    tambah(p) {
        const ada = this.items.find((it) => it.product_id === p.id);
        if (ada) {
            ada.quantity = this.angka(ada.quantity) + 1;
        } else {
            this.items.push({
                product_id: p.id, sku: p.sku, name: p.name, unit: p.unit, stock: p.stock,
                quantity: 1, unit_price: p.purchase_price, discount: 0,
            });
        }
        this.q = '';
        this.hasil = [];
        this.buka = false;
        this.$nextTick(() => this.$refs.cari?.focus());
    },
    hapus(i) {
        this.items.splice(i, 1);
    },
});
