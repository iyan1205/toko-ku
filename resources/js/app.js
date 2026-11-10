// Font Inter di-host sendiri (tanpa CDN). Hanya subset latin + latin-ext agar ukuran kecil.
import '@fontsource/inter/latin-400.css';
import '@fontsource/inter/latin-500.css';
import '@fontsource/inter/latin-600.css';
import '@fontsource/inter/latin-700.css';
import '@fontsource/inter/latin-ext-400.css';
import '@fontsource/inter/latin-ext-500.css';
import '@fontsource/inter/latin-ext-600.css';
import '@fontsource/inter/latin-ext-700.css';

import Alpine from 'alpinejs';
import cariGlobal from './cari-global';
import dokumenBarang from './dokumen-barang';
import grafikPenjualan from './grafik-penjualan';
import notifikasiStok from './notifikasi-stok';
import kasir from './pos/kasir';

window.Alpine = Alpine;

Alpine.data('cariGlobal', cariGlobal);
Alpine.data('dokumenBarang', dokumenBarang);
Alpine.data('grafikPenjualan', grafikPenjualan);
Alpine.data('notifikasiStok', notifikasiStok);
Alpine.data('kasir', kasir);

Alpine.start();
