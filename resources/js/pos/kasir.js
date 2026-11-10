/**
 * Layar Kasir (POS). Alpine: x-data="kasir(awal)" di resources/views/pos/index.blade.php.
 *
 * Semua angka di layar hanya pratinjau. Server (SaleService) selalu menghitung ulang dari harga
 * database dan menolak bila stok tidak cukup, sehingga keranjang ini tidak pernah "dipercaya mentah".
 * Hitungan memakai bilangan bulat (sen) dengan rumus yang sama dengan server (lihat ./hitung.js).
 *
 * Penjualan dikirim lewat fetch (JSON): bila ditolak, keranjang tetap utuh dan pesan tampil di dialog bayar.
 *
 * Shortcut: F2 cari/scan, F3 pelanggan, F4 diskon, F7 daftar ditahan, F8 bayar, F9 tahan, Esc batal/tutup.
 */
import { angka, hitungBayar, hitungPajak, kali, kurangiPersen, milli, rp, rupiahDari, sen } from './hitung';

export default function kasir(awal) {
    return {
        ...awal,

        // Pencarian & katalog
        q: '', kategori: null, hasilProduk: [], memuatProduk: false, nomorCari: 0,

        // Keranjang
        items: [], diskonTrans: 0, diskonMode: 'rp', diskonIsian: '', bukaBaris: null,
        pelanggan: null, qPelanggan: '', hasilPelanggan: [], bukaPelanggan: false,
        heldId: null, catatan: '',

        // Pembayaran
        bayar: [], piutang: false, galat: [], memproses: false, hasil: null,

        // Dialog: null | 'bayar' | 'diskon' | 'tahan' | 'batal' | 'selesai' | 'sesi'
        modal: null,
        daftarTahan: [], pesan: '',

        init() {
            this.muatTahan();
            this.fokusCari();
            window.addEventListener('pos-buka-sesi', () => this.bukaModal('sesi'));
        },

        // ---------- Format ----------
        rp, angka,

        // ---------- Harga & total (sen) ----------
        hargaSen(p) { return kurangiPersen(sen(p.selling_price), p.discount_percent); },
        kotorBaris(it) { return kali(milli(it.quantity), it.unit_price_sen); },
        diskonBaris(it) { return Math.min(sen(it.discount), this.kotorBaris(it)); },
        totalBaris(it) { return this.kotorBaris(it) - this.diskonBaris(it); },

        get kotor() { return this.items.reduce((s, it) => s + this.kotorBaris(it), 0); },
        get diskonItem() { return this.items.reduce((s, it) => s + this.diskonBaris(it), 0); },
        get bersih() { return this.kotor - this.diskonItem; },
        get diskonSen() { return Math.min(sen(this.diskonTrans), this.bersih); },
        get dasar() { return Math.max(0, this.bersih - this.diskonSen); },
        get pajakSen() { return hitungPajak(this.dasar, this.pajak); },
        get total() { return this.pajak.inclusive ? this.dasar : this.dasar + this.pajakSen; },
        get jumlahBarang() { return this.items.reduce((s, it) => s + (Number(it.quantity) || 0), 0); },

        // ---------- Produk ----------
        fokusCari() { this.$nextTick(() => this.$refs.cariProduk?.focus()); },

        async ambil(url) {
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(String(res.status));
            return res.json();
        },

        async cariProduk() {
            const kata = this.q.trim();
            if (kata === '' && this.kategori === null) { this.hasilProduk = []; return []; }

            const nomor = ++this.nomorCari;
            const params = new URLSearchParams();
            if (kata !== '') params.set('q', kata);
            if (this.kategori !== null) params.set('kategori', this.kategori);

            this.memuatProduk = true;
            try {
                const hasil = await this.ambil(this.urlCariProduk + '?' + params.toString());
                if (nomor !== this.nomorCari) return this.hasilProduk; // hasil pencarian lama, abaikan
                this.hasilProduk = hasil;
            } catch (e) {
                if (nomor === this.nomorCari) this.hasilProduk = [];
            }
            if (nomor === this.nomorCari) this.memuatProduk = false;

            return this.hasilProduk;
        },

        pilihKategori(id) {
            this.kategori = this.kategori === id ? null : id;
            this.cariProduk();
            this.fokusCari();
        },

        /** Enter di kotak cari (juga akhir hasil scan): tambah bila barcode/SKU cocok persis atau hasil tunggal. */
        async enterProduk() {
            if (this.q.trim() === '') return;
            const hasil = await this.cariProduk();
            const persis = hasil.find((p) => p.exact);
            if (persis) this.tambah(persis);
            else if (hasil.length === 1) this.tambah(hasil[0]);
        },

        stokCukup(p, tambahan = 1) {
            if (this.stokMinus) return true;
            const ada = this.items.find((it) => it.product_id === p.id);
            return (ada ? Number(ada.quantity) : 0) + tambahan <= Number(p.stock) + 1e-9;
        },

        tambah(p) {
            this.pesan = '';
            if (!this.stokCukup(p)) {
                this.pesan = `Stok ${p.name} di ${this.gudang} tinggal ${angka(p.stock)} ${p.unit ?? ''}.`;
                this.fokusCari();
                return;
            }

            const ada = this.items.find((it) => it.product_id === p.id);
            if (ada) {
                ada.quantity = Math.round((Number(ada.quantity) + 1) * 1000) / 1000;
                ada.stock = p.stock;
            } else {
                this.items.push({
                    product_id: p.id, sku: p.sku, name: p.name, unit: p.unit,
                    quantity: 1, unit_price_sen: this.hargaSen(p), discount: 0, stock: p.stock,
                });
            }

            // Hasil scan dikosongkan; kategori yang sedang dibuka tetap tampil.
            if (this.q.trim() !== '') { this.q = ''; this.kategori === null ? (this.hasilProduk = []) : this.cariProduk(); }
            this.fokusCari();
        },

        ubahJumlah(i, delta) {
            const it = this.items[i];
            const baru = Math.round((Number(it.quantity) + delta) * 1000) / 1000;
            if (delta > 0 && !this.stokMinus && baru > Number(it.stock) + 1e-9) {
                this.pesan = `Stok ${it.name} di ${this.gudang} tinggal ${angka(it.stock)} ${it.unit ?? ''}.`;
                return;
            }
            if (baru <= 0) { this.hapus(i); return; }
            it.quantity = baru;
        },

        batasiJumlah(i) {
            const it = this.items[i];
            if (!(Number(it.quantity) > 0)) it.quantity = 1;
            it.quantity = Math.round(Number(it.quantity) * 1000) / 1000;
        },

        hapus(i) {
            this.items.splice(i, 1);
            if (this.bukaBaris === i) this.bukaBaris = null;
            if (this.items.length === 0) this.diskonTrans = 0;
            this.fokusCari();
        },

        // ---------- Pelanggan ----------
        async cariPelanggan() {
            try { this.hasilPelanggan = await this.ambil(this.urlCariPelanggan + '?q=' + encodeURIComponent(this.qPelanggan.trim())); }
            catch (e) { this.hasilPelanggan = []; }
            this.bukaPelanggan = true;
        },
        bukaCariPelanggan() {
            this.bukaPelanggan = true;
            this.cariPelanggan();
            this.$nextTick(() => this.$refs.cariPelanggan?.focus());
        },
        pilihPelanggan(c) {
            this.pelanggan = c.is_default ? null : c;
            this.qPelanggan = '';
            this.bukaPelanggan = false;
            if (!this.bolehPiutang) this.piutang = false;
            this.fokusCari();
        },
        get bolehPiutang() { return this.piutangAktif && this.pelanggan !== null; },

        // ---------- Diskon transaksi ----------
        bukaDiskon() {
            if (this.items.length === 0) return;
            this.diskonMode = 'rp';
            this.diskonIsian = this.diskonSen > 0 ? String(rupiahDari(this.diskonSen)) : '';
            this.bukaModal('diskon');
        },
        get diskonPratinjau() {
            if (this.diskonMode === 'persen') {
                const persen = Math.min(100, Math.max(0, Number(String(this.diskonIsian).replace(',', '.')) || 0));
                return Math.min(this.bersih, Math.floor((this.bersih * Math.round(persen * 100) * 2 + 10000) / 20000));
            }
            return Math.min(this.bersih, Math.max(0, sen(this.diskonIsian)));
        },
        simpanDiskon() {
            this.diskonTrans = rupiahDari(this.diskonPratinjau);
            this.tutupModal();
        },

        // ---------- Pembayaran ----------
        get barisBayar() { return this.bayar.map((b) => ({ method: b.method, sen: Math.max(0, sen(b.amount)) })); },
        get ringkasBayar() { return hitungBayar(this.total, this.barisBayar); },
        get metodeTersisa() { return this.metode.filter((m) => !this.bayar.some((b) => b.method === m.value)); },
        labelMetode(kode) { return this.metode.find((m) => m.value === kode)?.label ?? kode; },

        get bolehSelesai() {
            if (this.items.length === 0 || this.memproses) return false;
            const r = this.ringkasBayar;
            if (r.lebihNonTunai) return false;
            if (this.piutang) return this.bolehPiutang && r.sisa > 0;
            return r.sisa <= 0;
        },

        bukaBayar() {
            if (this.items.length === 0) { this.pesan = 'Keranjang masih kosong.'; this.fokusCari(); return; }
            if (this.sesiWajib && !this.sesi) { this.bukaModal('sesi'); return; }
            this.galat = [];
            this.piutang = false;
            const pertama = this.metodeAwal;
            this.bayar = [{ method: pertama, amount: pertama === 'cash' ? '' : String(rupiahDari(this.total)) }];
            this.bukaModal('bayar');
            this.$nextTick(() => document.getElementById('bayar-0')?.select());
        },

        tambahMetode() {
            const sisa = this.metodeTersisa[0];
            if (!sisa) return;
            const kurang = Math.max(0, this.ringkasBayar.sisa);
            this.bayar.push({ method: sisa.value, amount: kurang > 0 ? String(rupiahDari(kurang)) : '' });
            this.$nextTick(() => document.getElementById('bayar-' + (this.bayar.length - 1))?.select());
        },
        hapusMetode(i) { this.bayar.splice(i, 1); },

        /** Ganti metode satu-satunya ke non-tunai: isi otomatis sebesar total (dibayar pas). */
        gantiMetode(i) {
            const b = this.bayar[i];
            if (this.bayar.length === 1 && b.method !== 'cash') b.amount = String(rupiahDari(this.total));
        },

        uangCepat() {
            const t = this.total;
            if (t <= 0) return [];
            const ribu = Math.ceil(t / 100000) * 100000;          // dibulatkan ke Rp 1.000
            const pilihan = [t, ribu, Math.ceil(t / 500000) * 500000, Math.ceil(t / 1000000) * 1000000, Math.ceil(t / 5000000) * 5000000, Math.ceil(t / 10000000) * 10000000];
            return [...new Set(pilihan)].filter((v) => v >= t).slice(0, 5);
        },
        isiTunai(nilaiSen) {
            let i = this.bayar.findIndex((b) => b.method === 'cash');
            if (i === -1) { this.bayar.push({ method: 'cash', amount: '' }); i = this.bayar.length - 1; }
            const lain = this.barisBayar.filter((b, j) => j !== i && b.method !== 'cash').reduce((s, b) => s + b.sen, 0);
            this.bayar[i].amount = String(rupiahDari(Math.max(0, nilaiSen === this.total ? this.total - lain : nilaiSen)));
        },

        payload() {
            return {
                customer_id: this.pelanggan ? this.pelanggan.id : null,
                items: this.items.map((it) => ({ product_id: it.product_id, quantity: it.quantity, discount: rupiahDari(this.diskonBaris(it)) })),
                discount: rupiahDari(this.diskonSen),
                payments: this.barisBayar.filter((b) => b.sen > 0).map((b) => ({ method: b.method, amount: rupiahDari(b.sen) })),
                payment_method: this.piutang ? 'receivable' : null,
                note: this.catatan.trim() || null,
                held_id: this.heldId,
            };
        },

        async selesaikan() {
            if (!this.bolehSelesai) return;
            this.memproses = true;
            this.galat = [];
            try {
                const res = await fetch(this.urlSimpanJual, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    credentials: 'same-origin',
                    body: JSON.stringify(this.payload()),
                });
                const data = await res.json().catch(() => ({}));

                if (res.ok) {
                    this.hasil = data;
                    this.resetKeranjang();
                    this.muatTahan();
                    this.modal = 'selesai';
                    this.$nextTick(() => (document.getElementById('tombol-cetak') ?? document.getElementById('tombol-baru'))?.focus());
                } else if (res.status === 419) {
                    this.galat = ['Sesi login sudah berakhir. Muat ulang halaman (keranjang akan kosong), lalu ulangi.'];
                } else {
                    this.galat = data.errors ? [...new Set(Object.values(data.errors).flat())] : [data.message || 'Penjualan gagal disimpan. Coba lagi.'];
                }
            } catch (e) {
                this.galat = ['Tidak bisa terhubung ke server. Periksa koneksi, lalu coba lagi.'];
            }
            this.memproses = false;
        },

        cetakStruk() {
            if (this.hasil?.receipt_url) window.open(this.hasil.receipt_url, 'struk', 'width=420,height=720');
        },

        transaksiBaru() {
            this.hasil = null;
            this.tutupModal();
            this.cariProduk();
        },

        resetKeranjang() {
            this.items = []; this.diskonTrans = 0; this.pelanggan = null; this.qPelanggan = ''; this.bukaBaris = null;
            this.bayar = []; this.piutang = false; this.heldId = null; this.catatan = ''; this.pesan = ''; this.galat = [];
        },

        konfirmasiBatal() {
            if (this.items.length === 0) { this.q = ''; this.fokusCari(); return; }
            this.bukaModal('batal');
        },
        batalkan() {
            this.resetKeranjang();
            this.tutupModal();
        },

        // ---------- Transaksi ditahan ----------
        async tahan() {
            if (this.items.length === 0 || this.memproses) return;
            this.pesan = '';
            try {
                const res = await fetch(this.urlTahan, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        customer_id: this.pelanggan ? this.pelanggan.id : null,
                        discount: rupiahDari(this.diskonSen),
                        note: this.catatan.trim() || null,
                        items: this.items.map((it) => ({ product_id: it.product_id, quantity: it.quantity, discount: rupiahDari(this.diskonBaris(it)) })),
                    }),
                });
                if (!res.ok) { const d = await res.json().catch(() => ({})); this.pesan = d.message || 'Gagal menahan transaksi.'; return; }
                // Keranjang yang dipanggil lalu ditahan lagi: simpanan lamanya dihapus agar tidak dobel.
                if (this.heldId) await this.hapusTahan(this.heldId);
                this.resetKeranjang();
                await this.muatTahan();
                this.pesan = 'Transaksi ditahan. Panggil lagi lewat Ditahan (F7).';
                this.fokusCari();
            } catch (e) { this.pesan = 'Gagal menahan transaksi.'; }
        },

        async muatTahan() {
            try { this.daftarTahan = await this.ambil(this.urlDaftarTahan); } catch (e) { this.daftarTahan = []; }
        },
        bukaTahan() { this.muatTahan(); this.bukaModal('tahan'); },

        async panggilTahan(id) {
            try {
                const d = await this.ambil(this.urlPanggilTahanTemplate.replace('__ID__', id));
                this.resetKeranjang();
                this.items = d.items.map((it) => ({
                    product_id: it.product_id, sku: it.sku, name: it.name, unit: it.unit,
                    quantity: Number(it.quantity), unit_price_sen: sen(it.unit_price), discount: it.discount || 0, stock: it.stock,
                }));
                this.diskonTrans = d.discount || 0;
                this.catatan = d.note || '';
                this.pelanggan = d.customer && !d.customer.is_default ? d.customer : null;
                this.heldId = id;
                this.tutupModal();
                if (d.produk_hilang > 0) this.pesan = d.produk_hilang + ' produk pada transaksi ini sudah tidak tersedia dan dilewati.';
            } catch (e) { this.pesan = 'Gagal memanggil transaksi yang ditahan.'; }
        },

        async hapusTahan(id) {
            try {
                await fetch(this.urlHapusTahanTemplate.replace('__ID__', id), {
                    method: 'DELETE',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.csrf },
                    credentials: 'same-origin',
                });
                if (this.heldId === id) this.heldId = null;
                await this.muatTahan();
            } catch (e) { /* diamkan: daftar akan sinkron saat dibuka lagi */ }
        },

        get slotTahan() { return Math.max(0, this.maksTahan - this.daftarTahan.length); },

        // ---------- Dialog & keyboard ----------
        bukaModal(nama) { this.modal = nama; this.bukaPelanggan = false; },
        tutupModal() {
            if (this.modal === 'selesai') this.hasil = null;
            this.modal = null;
            this.fokusCari();
        },

        shortcut(e) {
            const k = e.key;

            if (this.modal !== null) {
                if (k === 'Escape') {
                    e.preventDefault();
                    if (this.modal === 'selesai') this.transaksiBaru();
                    else if (!(this.modal === 'sesi' && this.sesiWajib && !this.sesi)) this.tutupModal();
                }
                else if (this.modal === 'bayar' && (k === 'F8' || (k === 'Enter' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'SELECT'))) { e.preventDefault(); this.selesaikan(); }
                else if (this.modal === 'batal' && k === 'Enter') { e.preventDefault(); this.batalkan(); }
                else if (this.modal === 'diskon' && k === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); this.simpanDiskon(); }
                else if (k.startsWith('F') && k.length > 1) e.preventDefault(); // F-key lain tidak membuka apa-apa saat dialog terbuka
                return;
            }

            if (k === 'F2') { e.preventDefault(); this.$refs.cariProduk.focus(); this.$refs.cariProduk.select(); }
            else if (k === 'F3') { e.preventDefault(); this.bukaCariPelanggan(); }
            else if (k === 'F4') { e.preventDefault(); this.bukaDiskon(); }
            else if (k === 'F7') { e.preventDefault(); this.bukaTahan(); }
            else if (k === 'F8') { e.preventDefault(); this.bukaBayar(); }
            else if (k === 'F9') { e.preventDefault(); this.tahan(); }
            else if (k === 'Escape') {
                if (this.bukaPelanggan) { this.bukaPelanggan = false; this.fokusCari(); }
                else if (this.bukaBaris !== null) { this.bukaBaris = null; this.fokusCari(); }
                else this.konfirmasiBatal();
            } else if (k.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey && !['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) {
                // Scanner atau ketikan saat fokus di luar isian: arahkan ke kotak cari agar tidak hilang.
                this.$refs.cariProduk.focus();
            }
        },
    };
}
