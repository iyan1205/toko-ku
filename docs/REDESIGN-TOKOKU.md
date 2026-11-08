# Redesign Toko-ku: Spesifikasi & Prompt untuk Claude Code

> Cara pakai:
> 1. Simpan file ini di root project Laravel Toko-ku sebagai `docs/REDESIGN-TOKOKU.md`.
> 2. Salin bagian **"Aturan Proyek"** ke file `CLAUDE.md` di root project (Claude Code otomatis membacanya setiap sesi).
> 3. Jalankan prompt **Fase 0 sampai Fase 7** satu per satu di Claude Code. Jangan sekaligus. Cek hasil dan commit setiap fase selesai.
> 4. Untuk fase besar, aktifkan Plan Mode dulu (Shift+Tab) agar Claude Code menyusun rencana sebelum mengubah kode.

---

## Aturan Proyek (salin ke CLAUDE.md)

```markdown
# Toko-ku — Aturan Proyek

## Konteks
Toko-ku adalah aplikasi manajemen toko (POS + back office) berbasis Laravel.
Modul: Dashboard, Kasir, Inventori, Pembelian, Laporan, Akuntansi, HR, Pengguna & Hak Akses, Pengaturan.
Akuntansi dan HR akan dibangun bertahap; strukturnya harus disiapkan sejak sekarang.
Spesifikasi lengkap: docs/REDESIGN-TOKOKU.md

## Aturan kerja
- Jangan merusak fitur yang sudah berjalan. Sebelum mengubah, baca dulu kode yang ada.
- Migration bersifat aditif: jangan drop tabel/kolom berisi data tanpa persetujuan saya.
- Jangan ubah file .env, kredensial, atau konfigurasi produksi.
- Kerjakan per fase, ringkas perubahan di akhir, lalu tunggu konfirmasi saya.
- Jalankan `php artisan test` (jika ada) dan `npm run build` setelah perubahan besar.
- Bahasa antarmuka: Bahasa Indonesia. Nama kode (class, variabel, tabel): Bahasa Inggris.
- Format uang: Rupiah, "Rp 1.250.000". Tanggal: "08 Nov 2026". Zona waktu: Asia/Jakarta.

## Konvensi
- Controller per modul: app/Http/Controllers/{Modul}/... (contoh: Inventory/ProductController)
- View per modul: resources/views/{modul}/... (contoh: inventory/products/index.blade.php)
- Route per modul: routes/modules/{modul}.php, di-include dari routes/web.php, prefix & name sesuai modul.
- Komponen UI reusable: resources/views/components/ui/* (Blade components).
- Permission format: `{modul}.{aksi}` contoh `inventory.products.view`, `pos.transactions.create`.
- Menu sidebar didefinisikan di config/menu.php, bukan hardcode di Blade.
- Validasi pakai Form Request. Logika bisnis berat di Service class (app/Services/{Modul}).
```

---

## Spesifikasi Desain

**Gaya:** dashboard bisnis modern, bersih, profesional (referensi gaya: aplikasi SaaS seperti Moka, Majoo, Odoo, Linear). Banyak ruang kosong, hierarki jelas, tidak ramai warna.

**Stack UI:** Tailwind CSS + Alpine.js (pertahankan stack yang sudah ada jika berbeda; tanyakan bila ragu). Ikon: Heroicons atau Lucide. Font: Inter.

**Warna (definisikan sebagai token di tailwind.config):**
- Primary: biru/indigo (contoh `#4F46E5`), dipakai hemat untuk aksi utama
- Netral: skala slate/gray untuk teks, border, background
- Status: success hijau, warning amber, danger merah, info sky
- Background halaman abu sangat muda, kartu putih dengan border tipis dan shadow halus
- Dukungan dark mode (opsional, toggle di topbar)

**Layout utama:**
- Sidebar kiri (lebar ~260px, bisa collapse ke ikon saja), grup menu dengan judul kecil, submenu bisa dibuka-tutup, menu aktif ter-highlight
- Topbar: tombol collapse, breadcrumb, pencarian global, notifikasi, pilihan cabang/toko (siapkan untuk multi-cabang), menu profil pengguna
- Area konten: judul halaman + deskripsi singkat + tombol aksi di kanan, lalu konten
- Responsif: di mobile sidebar jadi drawer
- Halaman Kasir memakai layout khusus layar penuh (tanpa sidebar), dioptimalkan untuk tablet dan keyboard/barcode scanner

**Komponen standar (Blade components):**
button, input, select, textarea, checkbox, badge status, card, stat-card (KPI), table (header sticky, sort, empty state, pagination), modal, dropdown, tabs, alert/toast notifikasi, confirm dialog hapus, breadcrumb, page-header, form-section, search & filter bar, skeleton loading.

---

## Struktur Menu

Setiap item punya: label, ikon, route, permission, dan flag modul (agar modul yang belum siap bisa disembunyikan atau diberi label "Segera Hadir").

| Grup | Menu | Submenu |
|---|---|---|
| Utama | Dashboard | Ringkasan penjualan, stok menipis, grafik, transaksi terakhir |
| Penjualan | **Kasir (POS)** | Transaksi Baru, Riwayat Transaksi, Sesi/Shift Kasir, Retur Penjualan, Pelanggan |
| Operasional | **Inventori** | Produk, Kategori, Satuan, Stok & Kartu Stok, Mutasi/Transfer Stok, Stok Opname, Gudang/Lokasi |
| Operasional | **Pembelian** | Supplier, Purchase Order, Penerimaan Barang, Retur Pembelian, Hutang Supplier |
| Keuangan | **Akuntansi** *(bertahap)* | Bagan Akun (COA), Jurnal Umum, Buku Besar, Kas & Bank, Laporan Keuangan (Laba Rugi, Neraca, Arus Kas), Tutup Buku |
| SDM | **HR** *(bertahap)* | Karyawan, Departemen & Jabatan, Absensi, Cuti & Izin, Penggajian |
| Analitik | Laporan | Penjualan, Pembelian, Stok, Laba Kotor, Kinerja Kasir |
| Sistem | **Pengguna & Hak Akses** | Pengguna, Peran (Role), Hak Akses (Permission matrix), Log Aktivitas |
| Sistem | **Pengaturan** | Profil Toko, Cabang, Pajak, Metode Pembayaran, Struk & Printer, Penomoran Dokumen, Backup |

**Peran bawaan:** Super Admin, Pemilik, Admin, Kasir, Staf Gudang, Purchasing, Akuntan, HRD.

**Integrasi antar modul (untuk dirancang sekarang, diimplementasi nanti):**
- Penjualan Kasir → otomatis mengurangi stok + membuat jurnal penjualan & HPP
- Penerimaan Barang → menambah stok + jurnal persediaan & hutang
- Penggajian HR → jurnal beban gaji
- Gunakan Event/Listener Laravel agar modul Akuntansi bisa "mendengarkan" transaksi tanpa mengubah kode modul lain.

---

## Prompt Bertahap

### Fase 0 — Audit (tanpa mengubah kode)

```
Baca CLAUDE.md dan docs/REDESIGN-TOKOKU.md. Lalu audit project ini TANPA mengubah file apa pun:
1. Versi Laravel & PHP, stack frontend (Blade/Livewire/Inertia/Vue/React), Tailwind atau Bootstrap, Vite/Mix.
2. Sistem auth yang dipakai dan apakah sudah ada role/permission.
3. Daftar model, migration, controller, dan route yang ada, dikelompokkan per modul di spesifikasi.
4. Layout & view yang ada sekarang serta masalah desainnya.
5. Fitur yang sudah ada vs yang belum ada dibanding Struktur Menu.
6. Risiko jika dilakukan redesign.
Tulis hasilnya ke docs/AUDIT.md, lalu berikan rencana implementasi fase 1-7 yang disesuaikan dengan kondisi project ini.
```

### Fase 1 — Fondasi desain & komponen

```
Kerjakan Fase 1 sesuai docs/REDESIGN-TOKOKU.md bagian Spesifikasi Desain:
- Siapkan/rapikan Tailwind (token warna, font Inter), Alpine.js, dan ikon.
- Buat Blade components di resources/views/components/ui untuk semua komponen standar.
- Buat halaman docs internal /dev/ui-kit (hanya untuk environment local) yang menampilkan semua komponen.
Jangan ubah halaman fitur dulu. Pastikan npm run build berhasil.
```

### Fase 2 — Layout utama & menu dinamis

```
Kerjakan Fase 2:
- Buat layout app (sidebar collapsible + topbar + breadcrumb + area konten) dan layout pos (layar penuh untuk kasir).
- Buat config/menu.php berisi seluruh Struktur Menu dari spesifikasi, lengkap dengan ikon, route, permission, dan flag modul (enabled / coming_soon / hidden). Akuntansi dan HR set ke coming_soon.
- Sidebar membaca config/menu.php, hanya menampilkan menu yang diizinkan untuk user, menandai menu aktif, dan memberi badge "Segera Hadir" untuk modul coming_soon.
- Buat halaman placeholder rapi untuk route yang belum ada fiturnya.
- Pindahkan halaman yang sudah ada agar memakai layout baru tanpa mengubah logikanya.
```

### Fase 3 — Pengguna & Hak Akses

```
Kerjakan Fase 3:
- Pasang spatie/laravel-permission (jika belum ada) dan migrasikan sistem role lama tanpa kehilangan data user.
- Buat seeder permission untuk SEMUA modul (termasuk Akuntansi & HR) dengan format {modul}.{fitur}.{aksi} dan aksi view/create/update/delete/export/approve sesuai kebutuhan.
- Buat seeder peran bawaan beserta hak aksesnya.
- Halaman: daftar pengguna (CRUD, aktif/nonaktif, reset password), daftar peran, dan matriks hak akses (baris = fitur, kolom = aksi, checkbox per peran, dikelompokkan per modul).
- Lindungi semua route dengan middleware permission. Tambahkan log aktivitas (spatie/laravel-activitylog atau sejenis).
```

### Fase 4 — Pengaturan & Dashboard

```
Kerjakan Fase 4:
- Modul Pengaturan: Profil Toko (logo, alamat, NPWP), Cabang, Pajak, Metode Pembayaran, Struk & Printer (preview struk), Penomoran Dokumen (format seperti INV/2026/11/0001), simpan di tabel settings dengan helper setting('key').
- Dashboard: stat-card KPI (penjualan hari ini, transaksi, produk stok menipis, hutang jatuh tempo), grafik penjualan 7/30 hari, transaksi terakhir, produk terlaris. Data menyesuaikan hak akses user.
```

### Fase 5 — Inventori & Pembelian

```
Kerjakan Fase 5 untuk menu Inventori dan Pembelian sesuai Struktur Menu.
- Redesign halaman yang sudah ada, lalu lengkapi yang belum ada.
- Semua perubahan stok wajib tercatat di tabel stock_movements (kartu stok) melalui satu StockService.
- Alur pembelian: PO (draft → disetujui) → Penerimaan Barang (bisa parsial) → stok bertambah → hutang supplier tercatat.
- Dispatch event (misal GoodsReceived) untuk dipakai modul Akuntansi nanti.
- Tabel daftar wajib punya pencarian, filter, sort, pagination, dan export Excel/PDF.
```

### Fase 6 — Kasir (POS)

```
Kerjakan Fase 6: redesign Kasir dengan layout pos.
- Kiri: pencarian produk + scan barcode + grid kategori. Kanan: keranjang, diskon, pajak, total besar, tombol bayar.
- Pembayaran multi-metode, hitung kembalian, cetak/preview struk, simpan transaksi tertunda (hold).
- Shortcut keyboard (F2 cari, F8 bayar, Esc batal), fokus otomatis ke input scan.
- Buka/tutup sesi kasir dengan modal awal dan rekap setoran.
- Gunakan StockService untuk mengurangi stok dan dispatch event SaleCompleted.
- Optimalkan untuk tablet 1024px dan layar desktop.
```

### Fase 7 — Kerangka Akuntansi & HR

```
Kerjakan Fase 7: siapkan kerangka modul Akuntansi dan HR (belum harus lengkap).
- Akuntansi: migration & model COA, jurnal (header + detail, debit = kredit divalidasi), seeder COA standar toko retail Indonesia, listener untuk event SaleCompleted dan GoodsReceived yang membuat jurnal otomatis (bisa dimatikan lewat setting).
- HR: migration & model karyawan, departemen, jabatan, relasi opsional ke tabel users.
- Halaman daftar & form dasar untuk COA, jurnal umum, dan karyawan.
- Ubah flag modul di config/menu.php menjadi enabled hanya untuk halaman yang sudah jadi.
Tulis ringkasan apa yang sudah dan belum dibangun ke docs/ROADMAP.md.
```

---

## Tips

- Jika hasil desain kurang pas, kirim screenshot ke Claude Code dan sebutkan apa yang ingin diubah ("sidebar terlalu ramai", "tabel kurang lega").
- Commit git setiap fase selesai, agar mudah kembali jika ada yang rusak.
- Untuk revisi kecil, gunakan prompt pendek dan spesifik, misalnya: "Di halaman Produk, tambahkan filter kategori dan status stok di search bar."
