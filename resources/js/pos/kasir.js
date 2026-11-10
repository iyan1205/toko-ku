/**
 * Layar Kasir (POS). Alpine: x-data="kasir(awal)" di resources/views/pos/index.blade.php.
 * Dipindah apa adanya dari script inline di view tersebut (Fase 6, langkah 1).
 */
// Semua angka di layar hanya pratinjau. Server (SaleService) selalu menghitung ulang dari harga
// database dan menolak bila stok tidak cukup, sehingga keranjang ini tidak pernah "dipercaya mentah".
export default function kasir(awal) {
    return {
        ...awal,
        q: '', hasilProduk: [], memuatProduk: false,
        items: [], diskonTrans: 0,
        qPelanggan: '', hasilPelanggan: [], bukaPelanggan: false, pelanggan: null,
        metode: awal.metodeAwal, diterima: 0, dp: 0, dpMetode: awal.dpMetodeAwal,
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
            this.metode = this.metodeAwal; this.diterima = 0; this.dp = 0; this.heldId = null; this.pesan = '';
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
