/**
 * Dropdown notifikasi di topbar: daftar produk yang perlu diisi ulang diambil
 * dari route('notifications') saat lonceng pertama kali dibuka, bukan di setiap halaman.
 *
 * @param {string} url  URL endpoint notifikasi (JSON)
 */
export default function notifikasiStok(url) {
    return {
        items: [],
        termuat: false,
        memuat: false,
        gagal: false,

        async ambil() {
            if (this.termuat || this.memuat) return;
            this.memuat = true;
            this.gagal = false;

            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                this.items = (await res.json()).items ?? [];
                this.termuat = true;
            } catch (e) {
                this.gagal = true;
            } finally {
                this.memuat = false;
            }
        },
    };
}
