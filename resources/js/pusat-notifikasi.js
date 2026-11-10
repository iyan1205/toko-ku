/**
 * Lonceng di topbar: notifikasi pengguna (route('notifications.feed')) dan, bila boleh melihat
 * stok, produk yang perlu diisi ulang (route('notifications')). Daftar diambil saat lonceng dibuka;
 * jumlah belum dibaca diperbarui berkala selama tab terlihat.
 *
 * @param {object} opsi
 * @param {string} opsi.feedUrl    JSON notifikasi
 * @param {string|null} opsi.stokUrl JSON stok menipis (null bila tidak boleh melihat stok)
 * @param {string} opsi.bacaSemuaUrl POST tandai semua dibaca
 * @param {number} opsi.belumDibaca jumlah awal belum dibaca (dari server)
 */
export default function pusatNotifikasi({ feedUrl, stokUrl = null, bacaSemuaUrl, belumDibaca = 0 }) {
    return {
        items: [],
        stok: [],
        belumDibaca,
        termuat: false,
        memuat: false,
        gagal: false,

        init() {
            // Perbarui angka lonceng tiap 2 menit selama halaman terlihat (notifikasi baru dari pengguna lain).
            this.timer = setInterval(() => {
                if (document.visibilityState === 'visible') this.hitung();
            }, 120000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        async ambilJson(url) {
            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        },

        async hitung() {
            try {
                const data = await this.ambilJson(feedUrl);
                this.belumDibaca = data.unread ?? 0;
                if (this.termuat) this.items = data.items ?? [];
            } catch (e) {
                // Diam saja: angka lama tetap tampil.
            }
        },

        async ambil() {
            if (this.termuat || this.memuat) return;
            this.memuat = true;
            this.gagal = false;

            try {
                const [feed, stok] = await Promise.all([
                    this.ambilJson(feedUrl),
                    stokUrl ? this.ambilJson(stokUrl) : Promise.resolve({ items: [] }),
                ]);
                this.items = feed.items ?? [];
                this.belumDibaca = feed.unread ?? 0;
                this.stok = stok.items ?? [];
                this.termuat = true;
            } catch (e) {
                this.gagal = true;
            } finally {
                this.memuat = false;
            }
        },

        async bacaSemua() {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            try {
                const res = await fetch(bacaSemuaUrl, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                this.belumDibaca = 0;
                this.items = this.items.map((item) => ({ ...item, read: true }));
            } catch (e) {
                this.gagal = true;
            }
        },
    };
}
