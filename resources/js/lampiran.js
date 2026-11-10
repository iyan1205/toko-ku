/**
 * Komponen lampiran dokumen (x-ui.attachments): unggah seret-lepas (tiap berkas satu permintaan),
 * pemeriksaan ukuran & jenis di browser (server memeriksa ulang), pratinjau, dan hapus.
 *
 * @param {object} opsi
 * @param {Array} opsi.items     lampiran yang sudah ada
 * @param {string} opsi.uploadUrl POST berkas (field "file")
 * @param {number} opsi.maxKb    ukuran maksimal per berkas
 * @param {string[]} opsi.ekstensi ekstensi yang boleh
 */
export default function lampiran({ items = [], uploadUrl, maxKb, ekstensi = [] }) {
    return {
        items,
        antrean: [],
        seret: false,
        galat: '',
        urut: 0,

        token() {
            return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        },

        periksa(file) {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            if (!ekstensi.includes(ext)) return `Jenis berkas .${ext} tidak didukung.`;
            if (file.size > maxKb * 1024) return `Ukuran melebihi batas ${maxKb >= 1024 ? Math.round(maxKb / 102.4) / 10 + ' MB' : maxKb + ' KB'}.`;
            return '';
        },

        unggah(daftar) {
            this.galat = '';
            Array.from(daftar ?? []).forEach((file) => this.kirim(file));
        },

        async kirim(file) {
            const tugas = { id: ++this.urut, name: file.name, error: this.periksa(file) };
            this.antrean.push(tugas);
            if (tugas.error) return this.lepas(tugas, 5000);

            const data = new FormData();
            data.append('file', file);

            try {
                const res = await fetch(uploadUrl, {
                    method: 'POST',
                    body: data,
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': this.token() },
                    credentials: 'same-origin',
                });
                const isi = await res.json().catch(() => ({}));
                if (!res.ok) {
                    tugas.error = isi.errors?.file?.[0] ?? isi.message ?? `Gagal mengunggah (HTTP ${res.status}).`;
                    return this.lepas(tugas, 6000);
                }
                this.items.unshift(isi.item);
                this.lepas(tugas, 0);
            } catch (e) {
                tugas.error = 'Gagal mengunggah. Periksa koneksi lalu coba lagi.';
                this.lepas(tugas, 6000);
            }
        },

        lepas(tugas, jeda) {
            setTimeout(() => {
                this.antrean = this.antrean.filter((t) => t.id !== tugas.id);
            }, jeda);
        },

        buka(item, modal) {
            if (!item.previewUrl) {
                window.location.href = item.downloadUrl;
                return;
            }
            window.dispatchEvent(new CustomEvent('pratinjau-lampiran', { detail: { modal, item } }));
            window.dispatchEvent(new CustomEvent('open-modal', { detail: modal }));
        },

        /** Konfirmasi lewat dialog bersama (x-ui.confirm-dialog), lalu halaman dimuat ulang. */
        hapus(item) {
            window.dispatchEvent(new CustomEvent('konfirmasi-hapus', {
                detail: { action: item.deleteUrl, nama: item.name, judul: 'Hapus lampiran?', pesan: 'akan dihapus dari dokumen ini.' },
            }));
        },
    };
}
