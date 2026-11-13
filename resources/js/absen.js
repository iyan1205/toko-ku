/**
 * Form absen masuk/pulang (Absensi & Layanan Mandiri): ambil lokasi GPS dan selfie.
 * Foto dikecilkan di browser (sisi terpanjang 960 px, JPEG) supaya muat batas unggah server.
 *
 * @param {object} opsi
 * @param {boolean} opsi.wajibLokasi cabang memakai radius absen: tombol aktif setelah lokasi didapat
 * @param {boolean} opsi.wajibFoto   selfie wajib (Pengaturan → SDM)
 */
export default function absen({ wajibLokasi = false, wajibFoto = false } = {}) {
    return {
        lokasi: '',
        statusLokasi: 'mencari',
        pratinjau: '',
        mengirim: false,
        galat: '',

        init() {
            this.cariLokasi();
        },

        cariLokasi() {
            if (!navigator.geolocation) {
                this.statusLokasi = 'gagal';
                return;
            }
            this.statusLokasi = 'mencari';
            navigator.geolocation.getCurrentPosition(
                (p) => {
                    this.lokasi = p.coords.latitude.toFixed(6) + ',' + p.coords.longitude.toFixed(6);
                    this.statusLokasi = 'ok';
                },
                () => (this.statusLokasi = 'gagal'),
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
            );
        },

        get siap() {
            return !this.mengirim && (!wajibLokasi || this.lokasi !== '') && (!wajibFoto || this.pratinjau !== '');
        },

        async pilihFoto(event) {
            const input = event.target;
            const file = input.files?.[0];
            this.galat = '';
            if (!file) {
                this.pratinjau = '';
                return;
            }
            try {
                const kecil = await this.kecilkan(file);
                const dt = new DataTransfer();
                dt.items.add(kecil);
                input.files = dt.files;
                this.pratinjau = URL.createObjectURL(kecil);
            } catch {
                // Browser lama: kirim berkas asli, server memeriksa ukurannya.
                this.pratinjau = URL.createObjectURL(file);
            }
        },

        kecilkan(file, sisi = 960) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                img.onload = () => {
                    const skala = Math.min(1, sisi / Math.max(img.width, img.height));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(img.width * skala);
                    canvas.height = Math.round(img.height * skala);
                    canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                    URL.revokeObjectURL(img.src);
                    canvas.toBlob(
                        (blob) => (blob ? resolve(new File([blob], 'selfie.jpg', { type: 'image/jpeg' })) : reject(new Error('gagal'))),
                        'image/jpeg',
                        0.8,
                    );
                };
                img.onerror = reject;
                img.src = URL.createObjectURL(file);
            });
        },

        kirim(event) {
            if (!this.siap) {
                event.preventDefault();
                this.galat = wajibFoto && !this.pratinjau ? 'Ambil foto selfie dulu.' : 'Lokasi belum terdeteksi. Izinkan akses lokasi lalu coba lagi.';
                return;
            }
            this.mengirim = true;
        },
    };
}
