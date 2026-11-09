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
import grafikPenjualan from './grafik-penjualan';
import notifikasiStok from './notifikasi-stok';

window.Alpine = Alpine;

Alpine.data('cariGlobal', cariGlobal);
Alpine.data('grafikPenjualan', grafikPenjualan);
Alpine.data('notifikasiStok', notifikasiStok);

Alpine.start();
