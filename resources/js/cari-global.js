/**
 * Pencarian global di topbar (layouts/partials/topbar.blade.php).
 * Memanggil route('search') dan menampilkan hasil per kelompok (Produk, Penjualan, ...).
 * Panah atas/bawah memilih hasil, Enter membuka, Esc menutup, "/" memfokuskan input.
 *
 * @param {string} url  URL endpoint pencarian (JSON)
 */
export default function cariGlobal(url) {
    return {
        q: '',
        hasil: [],
        buka: false,
        memuat: false,
        gagal: false,
        aktif: -1,
        mobile: false,
        permintaan: 0,

        /** Semua hasil dalam satu daftar, untuk navigasi keyboard. */
        get semua() {
            return this.hasil.flatMap((kelompok) => kelompok.items);
        },

        urutan(item) {
            return this.semua.indexOf(item);
        },

        async cari() {
            const kata = this.q.trim();
            this.buka = kata !== '';

            if (kata.length < 2) {
                this.hasil = [];
                this.gagal = false;
                this.aktif = -1;
                return;
            }

            // Abaikan jawaban lama bila pengguna sudah mengetik lagi.
            const nomor = ++this.permintaan;
            this.memuat = true;

            try {
                const res = await fetch(`${url}?q=${encodeURIComponent(kata)}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                if (nomor !== this.permintaan) return;

                this.hasil = data.groups ?? [];
                this.gagal = false;
                this.aktif = this.semua.length > 0 ? 0 : -1;
            } catch (e) {
                if (nomor !== this.permintaan) return;
                this.hasil = [];
                this.gagal = true;
            } finally {
                if (nomor === this.permintaan) this.memuat = false;
            }
        },

        pindah(langkah) {
            const jumlah = this.semua.length;
            if (jumlah === 0) return;
            this.buka = true;
            this.aktif = (this.aktif + langkah + jumlah) % jumlah;
        },

        pilih() {
            const item = this.semua[this.aktif];
            if (item) window.location.href = item.url;
        },

        tutup() {
            this.buka = false;
            this.mobile = false;
        },

        bukaMobile() {
            this.mobile = !this.mobile;
            if (this.mobile) this.$nextTick(() => this.$refs.input.focus());
        },

        /** "/" memfokuskan pencarian, kecuali saat sedang mengetik di isian lain. */
        pintasan(e) {
            if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
            const el = document.activeElement;
            if (el && (['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName) || el.isContentEditable)) return;

            e.preventDefault();
            this.mobile = window.matchMedia('(max-width: 767px)').matches;
            this.$nextTick(() => this.$refs.input.focus());
        },
    };
}
