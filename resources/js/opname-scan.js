/**
 * Hitung stok opname dari HP: ketik/scan kode (scanner = keyboard + Enter) atau kamera (BarcodeDetector,
 * Chrome Android). Setiap scan menambah 1 (atau jumlah yang diisi) ke hitungan produk di server.
 *
 * @param {object} opsi
 * @param {string} opsi.urlScan  POST kode/product_id, quantity, mode
 * @param {Array} opsi.items     hitungan yang sudah ada
 */
export default function opnameScan({ urlScan, items = [] }) {
    return {
        kode: '',
        jumlah: 1,
        items,
        pesan: '',
        galat: '',
        sibuk: false,
        kamera: false,
        bisaKamera: 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia,
        stream: null,

        token() {
            return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        },

        async kirim(data) {
            this.sibuk = true;
            this.galat = '';
            try {
                const res = await fetch(urlScan, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': this.token() },
                    credentials: 'same-origin',
                    body: JSON.stringify(data),
                });
                const isi = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.galat = isi.message || 'Gagal menyimpan hitungan.';
                    if (navigator.vibrate) navigator.vibrate([80, 60, 80]);
                    return;
                }
                const ada = this.items.findIndex((i) => i.product_id === isi.product_id);
                if (ada >= 0) this.items.splice(ada, 1);
                this.items.unshift(isi);
                this.pesan = `${isi.name}: ${isi.physical} ${isi.unit ?? ''}`;
                if (navigator.vibrate) navigator.vibrate(40);
            } catch (e) {
                this.galat = 'Koneksi terputus. Coba lagi.';
            } finally {
                this.sibuk = false;
            }
        },

        async scan() {
            const kode = this.kode.trim();
            if (kode === '' || this.sibuk) return;
            this.kode = '';
            await this.kirim({ kode, quantity: Number(this.jumlah) || 1, mode: 'add' });
            this.jumlah = 1;
            this.$refs.kode?.focus();
        },

        async ubah(item, nilai) {
            await this.kirim({ product_id: item.product_id, quantity: Math.max(0, Number(nilai) || 0), mode: 'set' });
        },

        async bukaKamera() {
            if (!this.bisaKamera) return;
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                this.kamera = true;
                await this.$nextTick();
                const video = this.$refs.video;
                video.srcObject = this.stream;
                await video.play();
                // eslint-disable-next-line no-undef
                const detektor = new BarcodeDetector();
                let terakhir = '';
                let jeda = 0;
                const putar = async () => {
                    if (!this.kamera) return;
                    try {
                        const hasil = await detektor.detect(video);
                        const nilai = hasil[0]?.rawValue;
                        if (nilai && (nilai !== terakhir || Date.now() > jeda)) {
                            terakhir = nilai;
                            jeda = Date.now() + 1500; // kode yang sama baru dihitung lagi setelah 1,5 detik
                            await this.kirim({ kode: nilai, quantity: 1, mode: 'add' });
                        }
                    } catch (e) { /* bingkai belum siap */ }
                    requestAnimationFrame(putar);
                };
                requestAnimationFrame(putar);
            } catch (e) {
                this.galat = 'Kamera tidak bisa dibuka. Izinkan akses kamera atau pakai scanner.';
                this.tutupKamera();
            }
        },

        tutupKamera() {
            this.kamera = false;
            this.stream?.getTracks().forEach((t) => t.stop());
            this.stream = null;
        },

        destroy() {
            this.tutupKamera();
        },
    };
}
