# Audit Toko-ku — Fase 0

Tanggal audit: 08 Nov 2026
Diperbarui: 08 Nov 2026, keputusan pemilik dicatat di bagian 7 dan rencana di bagian 8 disesuaikan.
Acuan: `CLAUDE.md`, `REDESIGN-TOKOKU.md` (spesifikasi redesign)
Cara audit: membaca kode dan konfigurasi tanpa mengubah file. Test dijalankan memakai SQLite in-memory (tidak menyentuh database asli). Build frontend diuji dengan output ke folder sementara di luar project.

---

## Ringkasan

- **Stack sudah sesuai spesifikasi**: Laravel 13 + Blade + Tailwind CSS v3 + Alpine.js + Vite. Tidak perlu ganti stack.
- **spatie/laravel-permission sudah terpasang dan dipakai** (3 peran, 31 izin). Fase 3 bukan "pasang", melainkan **migrasi format izin** dan **membuat peran dinamis**.
- **Backend cukup matang**: `StockService` sudah menjadi satu-satunya pintu perubahan stok, ada penguncian baris (lock) untuk transaksi bersamaan, pembatalan (void) dengan jejak, dan 374 test.
- **Sisi tampilan paling tertinggal**: dashboard masih placeholder, menu datar dengan ikon emoji, palet warna campur aduk, belum ada komponen UI standar, dan halaman Kasir masih memakai layout biasa (bukan layar penuh).
- **Belum ada sama sekali**: Akuntansi, Event/Listener antar modul, Purchase Order dan Penerimaan Barang terpisah, Sesi Kasir, Retur, Gudang, Cabang, Pajak penjualan, Peran dinamis, Log Aktivitas.
- **Tiga hal perlu dibereskan sebelum Fase 1**:
  1. Sekitar 60 file modul HR (Absensi, Jadwal, Shift, Cuti) **belum di-commit**.
  2. **11 test sudah gagal sebelum redesign dimulai**.
  3. Nama tabel `shifts` sudah dipakai untuk shift kerja HR, sehingga bentrok dengan "Sesi/Shift Kasir" di spesifikasi.
- **Keputusan pemilik (08 Nov 2026)**, lihat bagian 7: Admin boleh membuka Kasir, `subtotal` penjualan = sebelum diskon per item, semua paket tambahan disetujui (kecuali `spatie/laravel-backup` yang tidak bisa dipasang di PHP 8.3), dan multi-gudang dibutuhkan.

---

## 1. Stack teknis

| Komponen | Terpasang | Catatan |
|---|---|---|
| PHP | 8.3.36 | `composer.json` mensyaratkan `^8.3` |
| Laravel | 13.34.0 | |
| Frontend | **Blade + Alpine.js 3.17** | Tanpa Livewire, Inertia, Vue, atau React |
| CSS | **Tailwind CSS 3.4.19** lewat PostCSS + `@tailwindcss/forms` | `@tailwindcss/vite` **4.3.3 ikut terpasang tapi tidak dipakai** di `vite.config.js` (sisa, bisa membingungkan) |
| Bundler | **Vite 8.3** + `laravel-vite-plugin` 3.2 | Bukan Mix. Build berhasil (CSS 76 KB, JS 54 KB) |
| Font | Figtree (`tailwind.config.js`) | Spesifikasi minta Inter |
| Ikon | Emoji di `config/menu.php` + beberapa SVG inline | Belum ada library ikon |
| Scaffolding auth | `laravel/breeze` 2.4 (dev), dipangkas | |
| Paket lain | `maatwebsite/excel` 4.0, `spatie/laravel-permission` 8.3, `laravel/boost` 2.10 (dev) | Belum ada paket PDF, grafik, activity log, atau backup |
| Database | MySQL (`toko_ku`); session, cache, dan queue memakai driver `database` | |
| Test | PHPUnit 12.5, SQLite `:memory:` | 374 test, 1.643 assertion |
| Zona waktu | `Asia/Jakarta` | Sesuai |
| Bahasa | `APP_LOCALE=en` di `.env` | Folder `lang/id` sudah ada tapi **tidak aktif**, jadi pesan validasi dan login tampil dalam bahasa Inggris |

Isi database saat ini (hampir kosong, jadi risiko kehilangan data rendah): 4 user, 4 produk, 6 kategori, 8 satuan, 1 pelanggan, 0 penjualan, 0 pembelian, 0 mutasi stok, 1 absensi, 2 shift, 14 jadwal, 3 jenis cuti. Semua 20 migration sudah dijalankan.

---

## 2. Autentikasi & hak akses

**Autentikasi**
- Breeze versi Blade yang dipangkas: hanya **login dan logout** (`routes/auth.php`). Tidak ada registrasi publik. Akun dibuat Super Admin lewat menu Pengguna.
- `EnsureUserIsActive` (grup `web`) langsung mengeluarkan user yang dinonaktifkan. User memakai soft delete dan menyimpan `last_login_at`.
- Kode sisa tanpa route: `ProfileController`, `Auth/NewPasswordController`, `resources/views/profile/*`, `welcome.blade.php`, serta komponen Breeze `x-modal`, `x-text-input`, `x-primary-button`, dan lainnya (hanya dipakai di view profile yang tidak punya route).

**Role & permission: sudah ada**
- `spatie/laravel-permission` 8.3, trait `HasRoles` di `User`, alias middleware `role` dan `permission` sudah didaftarkan di `bootstrap/app.php`.
- **3 peran** di enum `App\Enums\RoleName`: Super Admin, Admin, Kasir. `Gate::before` meloloskan Super Admin untuk semua izin.
- **31 izin** berformat `kata-kerja-benda` (misal `manage-products`, `create-sale`), didefinisikan di `RolePermissionSeeder::IZIN`.
- Pengecekan izin lewat middleware `can:` di route, 6 Policy (Product, Sale, User, Absensi, Cuti, Jadwal), dan sekitar 87 titik `can`/`@can`/`hasRole` di kode dan view.

**Masalah yang ditemukan**
1. **Format izin berbeda dari spesifikasi.** Spesifikasi minta `{modul}.{fitur}.{aksi}` (misal `inventory.products.view`), sekarang `manage-products`.
2. **Peran tertanam sebagai enum.** `RoleName` dipakai sebagai *cast* kolom `peran` di `TahapPersetujuan` dan `PersetujuanCuti`, serta di validasi `StoreUserRequest`, `UpdateUserRequest`, dan `JenisCutiRequest`. Bila peran dibuat dinamis (halaman Peran di Fase 3), peran baru di database akan memicu error di modul Cuti.
3. **Matriks izin tidak konsisten antar sumber.** `README.md` (Tahap 1) bilang Admin boleh `create-sale`, `README-TAHAP5.md` bilang tidak, seeder memberi izin itu, dan test `PenjualanTest::test_hanya_pemegang_izin_create_sale...` mengharapkan 403. Perlu keputusan Anda.
4. **Izin tanpa fitur:** `view-dashboard`, `process-returns`, `manage-payables`, `manage-receivables` sudah ada di seeder tetapi tidak dipakai route mana pun.
5. **Belum ada:** halaman Peran, matriks hak akses, log aktivitas, reset password oleh admin (saat ini password diganti lewat form edit user).

---

## 3. Inventaris per modul

Semua controller saat ini datar di `app/Http/Controllers/` (belum ada subfolder per modul). Semua route ada di satu file, `routes/web.php` (111 route). URL berbahasa Indonesia, nama route berbahasa Inggris.

### Utama — Dashboard
| Jenis | Isi |
|---|---|
| Controller | `DashboardController` (invokable, placeholder) |
| Route | `GET /dashboard` → `dashboard` |
| View | `dashboard.blade.php` (sapaan + pintasan modul) |
| Pendukung | `App\Support\Menu` |

### Penjualan — Kasir (POS)
| Jenis | Isi |
|---|---|
| Model | `Sale`, `SaleItem`, `HeldSale`, `Payment` (polimorfik), `Customer` |
| Migration | `create_sales_tables` (sales, sale_items, held_sales), `create_customers_table`, `create_purchases_tables` (berisi tabel `payments`) |
| Controller | `PosController`, `SaleController`, `HeldSaleController`, `ReceiptController`, `StrukController`, `CustomerController` |
| Service | `SaleService`, `PaymentService`, `CustomerService`, `KodeService` |
| Route | `kasir/*` → `pos.*` (termasuk `pos.hold.*`), `penjualan/*` → `sales.*` (index, show, store, void, receipt, receipt.text), `pelanggan/*` → `customers.*` |
| View | `pos/index` (390 baris, Alpine inline), `sales/index`, `sales/show`, `sales/receipt` (halaman struk thermal mandiri), `customers/*` |
| Enum | `PaymentMethod` (cash, transfer, qris, debit, credit, ewallet, receivable), `PaymentStatus`, `PurchaseStatus` (dipakai juga untuk status penjualan) |
| Test | `PenjualanTest`, `PembatalanPenjualanTest`, `HeldSaleTest`, `PembayaranTest`, `StrukTeksTest`, `StrukThermalTest`, `SupplierPelangganTest` |

### Operasional — Inventori
| Jenis | Isi |
|---|---|
| Model | `Product`, `Category`, `Unit`, `StockMovement`, `StockOpname`, `StockOpnameItem`, `Recipe`, `RecipeItem` |
| Migration | categories, units, products, `add_type_to_products` (jual / bahan_baku), stock_movements, stock_opnames, recipes |
| Controller | `ProductController`, `CategoryController`, `UnitController`, `StockController`, `StockOpnameController`, `RecipeController` |
| Service | `ProductService`, `ProductImportService`, `StockService`, `StockOpnameService`, `RecipeService` |
| Route | `produk/*` → `products.*` (+ export, impor, template), `kategori` → `categories.*`, `satuan` → `units.*`, `stok`, `stok/mutasi`, `stok/penyesuaian` → `stock.*`, `stok-opname` → `stock-opnames.*`, `recipe` → `recipes.*` |
| View | `products/*`, `master/index` (dipakai bersama Kategori & Satuan), `stock/*`, `stock-opnames/*`, `recipes/*` |
| Lain | `Imports/ProductsImport`, `Exports/ProductsExport`, `ProductImportTemplate`, command `stok:periksa` |
| Test | `ProdukTest`, `ImporEksporProdukTest`, `KategoriSatuanTest`, `StockServiceTest`, `PenyesuaianStokTest`, `StokOpnameTest`, `PeriksaStokTest`, `RecipeTest` |

### Operasional — Pembelian
| Jenis | Isi |
|---|---|
| Model | `Purchase`, `PurchaseItem`, `Supplier`, `Payment` |
| Migration | suppliers, `create_purchases_tables` (purchases, purchase_items, payments) |
| Controller | `PurchaseController`, `SupplierController` |
| Service | `PurchaseService`, `SupplierService`, `PaymentService::bayarPembelian()` |
| Route | `pembelian/*` → `purchases.*` (index, create, store, show, void, products), `supplier/*` → `suppliers.*` |
| View | `purchases/*`, `suppliers/*` |
| Test | `PembelianTest`, `PembatalanPembelianTest`, `PembayaranTest` |

Catatan: "Pembelian" saat ini adalah **faktur pembelian langsung**. Begitu disimpan, stok langsung bertambah, harga beli diperbarui, dan pembayaran awal tercatat. Belum ada tahap PO maupun penerimaan barang terpisah.

### Keuangan — Akuntansi
Belum ada model, migration, controller, maupun route.

### SDM — HR (semua file belum di-commit)
| Jenis | Isi |
|---|---|
| Model | `Absensi`, `Shift`, `Jadwal`, `JenisCuti`, `TahapPersetujuan`, `Cuti`, `PersetujuanCuti` |
| Migration | `create_absensis_table`, `create_jadwal_kerja_tables` (shifts, jadwals), `create_cuti_tables` (jenis_cutis, tahap_persetujuans, cutis, persetujuan_cutis) |
| Controller | `AbsensiController`, `JadwalController`, `ShiftController`, `CutiController`, `JenisCutiController` |
| Service | `AbsensiService`, `JadwalService`, `CutiService`, `JenisCutiService` |
| Route | `absensi/*` → `attendances.*`, `jadwal` → `schedules.*`, `shift` → `shifts.*`, `cuti/*` → `leaves.*`, `jenis-cuti` → `leave-types.*` |
| View | `absensi/*`, `jadwal/index`, `shifts/index`, `cuti/*`, `jenis-cuti/*` |
| Enum | `StatusAbsensi`, `PerangkatAbsensi`, `StatusCuti`, `StatusPersetujuan` |
| Seeder | `KepegawaianSeeder` |
| Test | `AbsensiTest`, `JadwalTest`, `ShiftTest`, `CutiTest`, `JenisCutiTest` |

Catatan: modul HR memakai **nama tabel dan kolom berbahasa Indonesia** (`absensis`, `cutis`, `nama`, `tanggal`, `jam_masuk`), padahal aturan proyek meminta nama kode berbahasa Inggris. Pegawai = `users`; belum ada tabel karyawan.

### Analitik — Laporan
| Jenis | Isi |
|---|---|
| Controller | `LaporanController` |
| Pendukung | `App\Reports\LaporanPenjualan`, `Exports/LaporanPenjualanExport` + 5 sheet (Ringkasan, Harian, Produk, Kasir, Metode) |
| Route | `laporan` → `reports.index`, `laporan/penjualan/ekspor` → `reports.sales.export` |
| View | `reports/sales` |
| Test | `LaporanPenjualanTest` |

### Sistem — Pengguna & Hak Akses
| Jenis | Isi |
|---|---|
| Model | `User` (+ tabel bawaan spatie: roles, permissions, dan pivot-nya) |
| Migration | `create_users_table`, `add_pos_columns_to_users_table`, `create_permission_tables` |
| Controller | `UserController`, `Auth/AuthenticatedSessionController` |
| Service / Policy | `UserService`, `UserPolicy` |
| Route | `pengguna/*` → `users.*` (+ `users.toggle`), `login`, `logout` |
| View | `users/index`, `users/form`, `auth/login` |
| Middleware | `EnsureUserIsActive` |
| Test | `AutentikasiTest`, `HakAksesTest`, `PenggunaTest` |

### Sistem — Pengaturan
| Jenis | Isi |
|---|---|
| Model | `Setting` (key-value, `ambil()` / `simpan()` / `semua()`) |
| Migration | `create_settings_table` |
| Controller / Service | `SettingController`, `StoreProfileService` |
| Route | `pengaturan` → `settings.edit` / `settings.update` |
| View | `settings/store` (profil toko + lebar kertas struk) |
| Config | `config/pos.php` (stok minus, cetak otomatis, jam masuk absensi), dibaca dari `.env` |
| Test | `ProfilTokoTest` |

### Lintas modul
`app/Support/Format.php` (`@rupiah`, `@tanggal`, durasi), `Uang.php` (hitungan uang dalam sen), `StrukTeks.php`, `Menu.php`. Exception `AturanBisnisException` ditampilkan sebagai pesan flash, bukan halaman error 500.

---

## 4. Layout & view saat ini

**Layout**
- `layouts/app.blade.php` (lewat komponen `<x-app-layout>`): sidebar kiri tetap 256px, menjadi drawer di mobile, topbar berisi judul halaman. Grup menu didefinisikan *hardcode* di Blade (Transaksi, Katalog, Relasi, Pegawai, Lainnya), sedangkan item menu dibaca dari `config/menu.php`.
- `layouts/guest.blade.php`: halaman login.
- `errors/layout.blade.php`: halaman 403, 404, 419, 500.
- `sales/receipt.blade.php`: halaman struk thermal mandiri dengan CSS cetak sendiri.
- 41 halaman memakai `<x-app-layout>` dengan slot `header` saja.

**Komponen**: hanya `x-isian` (28 pemakaian) yang dipakai luas, ditambah partial `toast`, `confirm-delete`, dan `badge-*`. Gaya bersama ditaruh sebagai kelas CSS di `resources/css/app.css` (`.kartu`, `.tombol-utama`, `.tombol-kedua`, `.tombol-bahaya`, `.isian`, `.tabel`, `.badge-*`), dipakai lebih dari 270 kali.

**Masalah desain**
1. **Palet warna campur aduk.** Dalam view terdapat `stone-*` sekitar 600×, `brand-*` 311×, `slate-*` 201×, `gray-*` 65×. Token `brand` saat ini berisi nilai slate (abu kebiruan), dan komentar di `guest.blade.php` masih menyebut "merah gelap" (sisa rebranding sebelumnya).
2. **Warna "bahaya" bukan merah.** `.tombol-bahaya` memakai amber, dan tombol Hapus di daftar produk memakai `brand` (abu). Pemakaian `red-*` = 0. Aksi berbahaya tidak terlihat berbahaya.
3. **Ikon emoji** di menu, logo (🏪), dan placeholder foto produk (📦). Tampilannya beda di tiap OS dan kurang profesional.
4. **Input form tidak konsisten.** Sebagian memakai `.isian` atau `x-isian`, sebagian menulis kelas sendiri (misal `rounded-md border-stone-300 ... focus:border-brand-600` di filter produk dan master).
5. **Belum ada kerangka halaman standar.** Tidak ada breadcrumb, deskripsi halaman, atau area tombol aksi di kanan judul. Tombol aksi diletakkan di dalam kartu, beda posisi di tiap halaman.
6. **Tabel**: belum ada sort, header sticky, atau skeleton loading. Empty state dan pagination dibuat manual per halaman.
7. **Sidebar**: belum bisa diciutkan (collapse) ke ikon, belum ada submenu, item datar 22 baris, dan grup menu di-hardcode di Blade.
8. **Topbar**: hanya tombol menu mobile dan judul. Belum ada pencarian, notifikasi, pilihan cabang, atau menu profil (info user dan tombol Keluar ada di bawah sidebar).
9. **Kasir memakai layout biasa** dengan sidebar, sehingga ruang layar di tablet terbuang. Logika Alpine sepanjang 390 baris ditulis inline di Blade.
10. **Dashboard** hanya berisi sapaan dan kartu pintasan modul, tanpa angka bisnis.
11. **Font Figtree**, bukan Inter. Belum ada dark mode.
12. Kartu dan tabel sebenarnya sudah lumayan bersih (border tipis, shadow halus). Ini bisa dijadikan dasar, bukan dibuang.

---

## 5. Fitur ada vs belum ada (dibanding Struktur Menu)

Keterangan: **Ada** = berfungsi, **Sebagian** = ada sebagian logika atau tampilan, **Belum** = tidak ada.

| Grup | Menu | Submenu | Status | Catatan |
|---|---|---|---|---|
| Utama | Dashboard | Ringkasan penjualan, stok menipis, grafik, transaksi terakhir | **Belum** | Placeholder sapaan + pintasan |
| Penjualan | Kasir | Transaksi Baru | **Ada** | Cari/scan, diskon baris & transaksi, 7 metode bayar, kembalian, piutang + DP, struk thermal + RawBT. Belum layar penuh, belum split payment, belum ada pajak |
| | | Riwayat Transaksi | **Ada** | Daftar, detail, void dengan alasan. Kasir hanya melihat transaksinya sendiri |
| | | Sesi/Shift Kasir | **Belum** | Tabel `shifts` sudah dipakai untuk shift kerja HR |
| | | Retur Penjualan | **Belum** | Izin `process-returns` dan tipe mutasi `sale_return` sudah ada |
| | | Pelanggan | **Ada** | CRUD + saldo piutang. Pelunasan piutang sudah ada di `PaymentService` tetapi belum ada halamannya (menu `receivables.index` tidak punya route) |
| | | *(Transaksi ditahan / hold)* | **Ada** | Maksimal sejumlah slot per kasir |
| Operasional | Inventori | Produk | **Ada** | CRUD, foto, impor/ekspor Excel, filter. Tipe jual / bahan baku |
| | | Kategori | **Ada** | Modal CRUD |
| | | Satuan | **Ada** | Modal CRUD |
| | | Stok & Kartu Stok | **Ada** | Daftar stok + status, riwayat mutasi per produk, penyesuaian manual (rusak/hilang/koreksi) |
| | | Mutasi/Transfer Stok | **Belum** | URL `stok/mutasi` saat ini berarti *log mutasi*, bukan transfer antar gudang |
| | | Stok Opname | **Ada** | Langsung final (tanpa draft/persetujuan) |
| | | Gudang/Lokasi | **Belum** | Stok disimpan satu angka di `products.stock` |
| | | *(Resep / BOM)* | **Sebagian** | Menghitung biaya resep. Penjualan **tidak** mengurangi stok bahan baku |
| Operasional | Pembelian | Supplier | **Ada** | |
| | | Purchase Order | **Belum** | |
| | | Penerimaan Barang | **Sebagian** | Menyatu dengan faktur pembelian (stok langsung masuk). Belum ada penerimaan parsial |
| | | Retur Pembelian | **Belum** | Tipe mutasi `purchase_return` sudah ada |
| | | Hutang Supplier | **Sebagian** | Kolom jatuh tempo/sisa dan `PaymentService::bayarPembelian()` + test sudah ada. Belum ada halaman (menu `payables.index` tidak punya route) |
| Keuangan | Akuntansi | COA, Jurnal Umum, Buku Besar, Kas & Bank, Laporan Keuangan, Tutup Buku | **Belum** | `sale_items.cost_price` sudah menyimpan HPP per transaksi, berguna untuk jurnal HPP nanti |
| SDM | HR | Karyawan | **Belum** | Pegawai = `users` |
| | | Departemen & Jabatan | **Belum** | |
| | | Absensi | **Ada** | Masuk/pulang, GPS, perangkat, catatan manual admin, status terlambat. *Belum di-commit* |
| | | Cuti & Izin | **Ada** | Persetujuan bertingkat per jenis cuti, kuota tahunan, otomatis tercatat ke absensi. *Belum di-commit* |
| | | Penggajian | **Belum** | |
| | | *(Jadwal & Shift kerja)* | **Ada** | Tidak ada di Struktur Menu, perlu ditempatkan di HR. *Belum di-commit* |
| Analitik | Laporan | Penjualan | **Ada** | Ringkasan, harian, per produk, per kasir, per metode + ekspor Excel |
| | | Pembelian | **Belum** | |
| | | Stok | **Belum** | Ada halaman stok, belum ada laporan nilai persediaan |
| | | Laba Kotor | **Sebagian** | Sudah dihitung di laporan penjualan |
| | | Kinerja Kasir | **Sebagian** | Ada sheet "Kasir" di laporan penjualan |
| Sistem | Pengguna & Hak Akses | Pengguna | **Ada** | CRUD, aktif/nonaktif, proteksi Super Admin terakhir. Belum ada tombol reset password khusus |
| | | Peran (Role) | **Belum** | 3 peran tetap di enum |
| | | Hak Akses (matriks) | **Belum** | Diatur lewat seeder |
| | | Log Aktivitas | **Belum** | Hanya `last_login_at` dan jejak void di transaksi |
| Sistem | Pengaturan | Profil Toko | **Sebagian** | Nama, pemilik, alamat, telepon, IG, TikTok. Belum ada logo dan NPWP |
| | | Cabang | **Belum** | |
| | | Pajak | **Belum** | Pembelian punya kolom `tax`, **penjualan tidak** |
| | | Metode Pembayaran | **Belum** | Hardcode di enum `PaymentMethod` |
| | | Struk & Printer | **Sebagian** | Lebar kertas 58/80 mm, footer, cetak otomatis (lewat `.env`). Belum ada halaman preview khusus |
| | | Penomoran Dokumen | **Belum** | Awalan tertanam di kode: `POS-Ymd-0001`, `PUR-`, `PAY-`, `SUP-`, `CUS-`, `PRD-` |
| | | Backup | **Belum** | |

Peran bawaan: spesifikasi meminta 8 (Super Admin, Pemilik, Admin, Kasir, Staf Gudang, Purchasing, Akuntan, HRD). Saat ini ada 3.

Integrasi antar modul: **belum ada Event/Listener sama sekali**. Satu-satunya `event()` di `app/` adalah bawaan Laravel (Lockout, PasswordReset).

---

## 6. Risiko redesign

| # | Risiko | Dampak | Mitigasi |
|---|---|---|---|
| 1 | **Sekitar 60 file modul HR belum di-commit** (plus perubahan di `routes/web.php`, `config/menu.php`, seeder, migration recipes) | Tinggi: bila redesign gagal, pekerjaan HR ikut sulit dikembalikan | **Selesai 08 Nov 2026**: di-commit sebagai `02c4c97` di branch `redesign-tokoku` |
| 2 | **11 test sudah gagal sebelum redesign** (lihat rincian di bawah) | Tinggi: kerusakan akibat redesign tidak bisa dibedakan dari kerusakan lama | **Selesai 08 Nov 2026**: 9 test diperbaiki, 372/374 lulus. Tidak ada bug di kode aplikasi; semuanya test usang atau data test yang salah. 2 sisanya menunggu izin folder (risiko #20) |
| 3 | **Bentrok nama "Shift"**: tabel `shifts` dan route `shifts.*` sudah dipakai shift kerja HR | Sedang: migration atau route baru untuk sesi kasir bisa bentrok | Sesi kasir memakai nama `cash_sessions` dan route `pos.sessions.*` |
| 4 | **Bentrok nama "Mutasi"**: `stok/mutasi` = log pergerakan stok, sedangkan spesifikasi memakai "Mutasi/Transfer" untuk pindah antar gudang | Rendah–sedang: membingungkan pengguna | Ganti label menu lama menjadi "Kartu Stok". Transfer memakai `inventory.transfers.*` |
| 5 | **Ganti format izin** (`manage-products` menjadi `inventory.products.update`) menyentuh middleware route, Policy, `@can` di view, seeder, dan test (sekitar 87 titik + 6 Policy) | Tinggi: satu nama salah berarti user terkunci (403) atau justru bisa mengakses yang tidak boleh | Siapkan tabel pemetaan lama → baru, migration data yang *mengganti nama* baris di `permissions` (id tetap, jadi relasi ke peran aman), dan konstanta/enum izin agar tidak typo. Tambah test per peran |
| 6 | **Peran dinamis vs enum `RoleName`**: kolom `peran` di modul Cuti di-*cast* ke enum | Tinggi: peran baru dari halaman Peran membuat modul Cuti error | Ubah cast menjadi string atau `role_id`, dan validasi memakai `Rule::exists('roles','name')`. Pertahankan enum hanya untuk peran sistem (Super Admin) |
| 7 | **Memindah controller ke `Controllers/{Modul}/` dan route ke `routes/modules/*.php`**: nama route dipakai di 41 view, JavaScript Kasir, `config/menu.php`, dan test | Sedang | Pertahankan **URL dan nama route lama** saat memindah file. Bandingkan output `php artisan route:list` sebelum dan sesudah. Pindahkan controller per modul saat modul itu di-redesign, bukan sekaligus |
| 8 | **Ubah alur pembelian** (faktur langsung menjadi PO → Penerimaan → Hutang) | Tinggi: inti stok dan hutang | Tambahan bersifat aditif: tabel `purchase_orders` dan `goods_receipts` baru, kolom `purchase_order_id` nullable di `purchases`. Opsi "pembelian langsung tanpa PO" tetap ada untuk toko kecil |
| 9 | **Multi-gudang (diputuskan: dibutuhkan)**: stok saat ini satu angka di `products.stock` dan `StockService` belum mengenal lokasi | Tinggi: menyentuh penjualan, pembelian, penyesuaian, opname, dan laporan | Dikerjakan paling awal di Fase 5a, sebelum alur pembelian baru dan redesign Kasir. Data hampir kosong (0 mutasi, 4 produk), jadi pemindahan stok awal ke "Gudang Utama" mudah. `products.stock` tetap menjadi total semua gudang supaya kode lama tetap jalan. Rancangan lengkap ada di Fase 5a |
| 10 | **Ganti token warna `brand`** dari slate ke indigo langsung mengubah 311 pemakaian di semua halaman | Sedang: tampilan berubah sebagian-sebagian | Tambah token baru (`primary`, `success`, `warning`, `danger`, `info`) tanpa mengubah `brand`. Migrasi per halaman di Fase 2, lalu hapus `brand`/`accent` di akhir |
| 11 | **Upgrade ke Tailwind v4**: `app.css` memakai `@apply` bersarang ke kelas sendiri (misal `@apply tombol ...`) yang tidak didukung v4, dan konfigurasi v4 berbasis CSS | Tinggi bila dipaksakan | **Tetap di Tailwind v3.4** selama redesign. Paket `@tailwindcss/vite` v4 yang tidak terpakai dihapus (sudah disetujui) |
| 12 | **Test yang memeriksa isi HTML** (misal `test_halaman_kasir_meneruskan_pengaturan_stok_minus_ke_javascript`, `test_kasir_hanya_melihat_transaksi_miliknya_sendiri`) | Sedang: perubahan markup bisa membuat test merah walau logika benar | Saat mengubah view, periksa test terkait dan ubah assertion ke hal yang bermakna (data atau status), bukan potongan markup |
| 13 | **Redesign Kasir**: 390 baris Alpine inline menangani keranjang, hold, piutang, dan shortcut | Tinggi: kasir adalah alur uang | Fase 6: pindahkan JS ke `resources/js/pos/` tanpa mengubah logika dulu, baru ubah tampilan. Uji manual dengan scanner dan tablet |
| 14 | **Struk thermal** (`sales/receipt`) punya CSS cetak sendiri | Sedang: bila ikut dibungkus layout baru, struk rusak | Jangan bungkus dengan layout app atau pos. Halaman struk tetap mandiri |
| 15 | **Shortcut kasir berubah**: sekarang F8 = fokus diskon dan F9 = tahan, spesifikasi minta F8 = bayar | Rendah: kasir yang sudah hafal bisa salah tekan | Tetapkan peta shortcut baru, tampilkan legenda di layar, dan beri tahu kasir |
| 16 | **Halaman `/dev/ui-kit` "hanya local"**: `.env` server ini `APP_ENV=local` dan `APP_DEBUG=true` dengan `APP_URL` domain publik | Tinggi bila server ini bisa diakses publik: UI kit dan stack trace error ikut terbuka | Lindungi UI kit dengan `auth` + izin Super Admin, bukan hanya cek environment. Anda perlu meninjau `APP_ENV`/`APP_DEBUG` di server (saya tidak mengubah `.env`) |
| 17 | **Bahasa antarmuka**: `APP_LOCALE=en`, jadi pesan validasi tampil dalam bahasa Inggris (menyebabkan 1 test gagal) | Rendah | **Selesai 08 Nov 2026**: `.env` diubah pemilik ke `id`. `phpunit.xml` dan `.env.example` ikut memakai `id` agar test tidak bergantung pada `.env` |
| 18 | **Penamaan campur** (tabel HR berbahasa Indonesia, nama method seperti `catat()`/`buat()`, `LaporanController`) vs aturan "kode berbahasa Inggris" | Rendah–sedang: rename tabel berisi data berisiko | Jangan rename tabel atau kolom yang ada. Terapkan bahasa Inggris untuk kode **baru** saja |
| 19 | **Kecocokan paket dengan PHP 8.3** (semua paket sudah disetujui) | Sedang: `composer require` bisa gagal atau memilih versi lama | `spatie/laravel-activitylog` 5.x butuh PHP 8.4, jadi pakai **4.12** (mendukung Laravel 13). `spatie/laravel-backup` versi yang mendukung Laravel 13 (10.x) **butuh PHP 8.4**, versi 9.x hanya sampai Laravel 12, jadi **tidak bisa dipasang** di server ini (PHP 8.3). Penggantinya: perintah backup sendiri memakai `mysqldump` (sudah ada di server), atau upgrade PHP ke 8.4 |
| 22 | **Arti `subtotal` beda antara penjualan dan pembelian**: penjualan = sebelum diskon per item (sesuai keputusan), pembelian = sesudah diskon per baris | Rendah–sedang: laporan dan jurnal bisa salah tafsir | Samakan di Fase 5b: tambah kolom `item_discount` di `purchases`, `subtotal` = sebelum diskon. Data pembelian masih 0 baris |
| 20 | **Izin folder `storage/` dan `bootstrap/cache/`** dimiliki `www-data`, sehingga 2 test upload foto gagal saat dijalankan user `hamori`, dan `composer require` tidak bisa memperbarui daftar paket (`bootstrap/cache/packages.php`). Akibatnya paket baru (Heroicons, juga Laravel Boost sejak 7 Nov) belum dikenali aplikasi | Rendah (lingkungan dev) | Folder sudah bisa ditulis grup (`rwxrwxr-x www-data`), tetapi user `hamori` bukan anggota grup `www-data`. Jalankan `sudo usermod -aG www-data hamori`, lalu login ulang. Kedua test sudah terbukti lulus dengan folder storage yang bisa ditulis. Setelah setiap `composer require`, jalankan `sudo -u www-data php artisan package:discover` |
| 21 | **Dokumen proyek belum di tempat yang diharapkan**: spesifikasi ada di root (`REDESIGN-TOKOKU.md`), bukan `docs/`, dan `CLAUDE.md` belum memuat bagian "Aturan Proyek" Toko-ku | Rendah: sesi berikutnya bisa tidak membaca aturan proyek | Spesifikasi **sudah dipindah** ke `docs/REDESIGN-TOKOKU.md` (08 Nov 2026). Aturan Proyek **belum** disalin ke `CLAUDE.md` |

**Rincian 11 test yang gagal sebelum redesign** (`vendor/bin/phpunit`: 374 test, 363 lulus, 7 gagal, 4 error)

| Test | Gejala | Dugaan penyebab |
|---|---|---|
| `AutentikasiTest::test_login_gagal_dengan_kata_sandi_salah` | Pesan "These credentials do not match our records." | `APP_LOCALE=en` (risiko #17) |
| `PenjualanTest::test_hanya_pemegang_izin_create_sale_dapat_mengakses_kasir_dan_menjual` | Dapat 200, test mengharapkan 403 | **Test usang.** Keputusan: Admin boleh membuka Kasir. Ganti Admin dengan user tanpa izin `create-sale` |
| `PenjualanTest::test_diskon_baris_mengurangi_subtotal_baris` | `subtotal` 10000.00, test mengharapkan 8500.00 | **Test usang.** Keputusan: `subtotal` = sebelum diskon per item, jadi kode sudah benar. Ubah harapan test menjadi `subtotal` 10000.00, `item_discount` 1500.00, `grand_total` 8500.00 |
| `PenjualanTest::test_kasir_hanya_melihat_transaksi_miliknya_sendiri` | Assertion isi HTML gagal | Perlu dicek: kebocoran data antar kasir atau test usang |
| `PenjualanTest::test_halaman_kasir_meneruskan_pengaturan_stok_minus_ke_javascript` | Assertion isi HTML gagal | Markup Kasir berubah (commit "kasir") |
| `HeldSaleTest::test_kasir_dapat_menahan_dan_memanggil_kembali_transaksi` | `10000` vs `10000.0` | Tipe angka di JSON keranjang |
| `PembelianTest::test_produk_yang_tidak_ada_menggagalkan_pembelian_tanpa_sisa` | Exception jenis lain ("Pembayaran melebihi total pembelian.") | Urutan validasi di `PurchaseService` berubah |
| `PembelianTest::test_produk_yang_sama_dua_kali_ditolak` | `Call to a member function keys() on array` | Bug di kode atau test |
| `PembatalanPenjualanTest::test_pembatalan_mengembalikan_stok...` | "Database connection [...] not configured" | Argumen pesan diletakkan di posisi parameter *connection* pada `assertDatabaseCount` (bug di test) |
| `ProdukTest::test_foto_dapat_diunggah_diganti_dan_dihapus` | Tidak bisa membuat folder `storage/framework/testing` | Izin folder (risiko #20) |
| `ProdukTest::test_berkas_bukan_gambar_ditolak` | Sama | Sama |

**Hasil perbaikan (08 Nov 2026):** tidak ada bug di kode aplikasi. Semua kegagalan berasal dari test yang usang atau data test yang salah:
- *Kasir melihat transaksi kasir lain*: **bukan kebocoran data**. Nomor transaksi kasir lain hanya muncul di pesan toast "Penjualan ... tersimpan" sisa POST sebelumnya, karena test memakai satu sesi untuk dua kasir dan tidak mengikuti redirect. Filter "milik sendiri" di `SaleController@index` bekerja benar. Test kini memakai `flushSession()` saat berganti kasir.
- *Stok minus ke JavaScript*: `@js()` menulis tanda kutip sebagai `\u0022`, sehingga teks yang dicari diperbarui.
- *Hold*: JSON mengirim `10000` sebagai bilangan bulat, sehingga harapan test diperbarui.
- *Produk tidak ada (pembelian)*: data test diperbaiki (pembayaran disesuaikan dengan total).
- *Produk dobel (pembelian)*: memakai `assertSessionHasErrors` dengan pesan yang diharapkan.
- *Pembatalan penjualan*: pesan dipindah dari posisi parameter koneksi ke komentar.
- *Izin Kasir untuk Admin* dan *`subtotal`*: disesuaikan dengan keputusan bagian 7. `HakAksesTest` sekarang juga memastikan Admin memegang `create-sale`.
- *Login*: lulus setelah `APP_LOCALE=id`.
- *Upload foto (2 test)*: lulus bila folder storage bisa ditulis; tinggal izin folder di server (risiko #20).

---

## 7. Keputusan

### Diputuskan pemilik (08 Nov 2026)

| # | Pertanyaan | Keputusan | Akibatnya |
|---|---|---|---|
| 1 | Admin boleh membuka Kasir (`create-sale`)? | **Boleh** | Seeder sudah benar. Test `test_hanya_pemegang_izin_create_sale...` dan tabel hak akses di `README-TAHAP5.md` diperbaiki. Matriks Fase 3: Admin memegang `pos.transactions.create` |
| 2 | Arti `subtotal` penjualan | **Sebelum diskon per item** | Kode `SaleService` sudah benar, test `test_diskon_baris_mengurangi_subtotal_baris` diperbaiki. Rumus: `grand_total = subtotal − item_discount − discount (+ pajak, Fase 6)`. Pembelian disamakan di Fase 5b (risiko #22) |
| 3 | Paket tambahan | **Semua disetujui** | Lihat daftar paket di bawah |
| 4 | Multi-gudang | **Dibutuhkan** | Fase 5 dipecah: **5a Multi-gudang & Inventori** dikerjakan dulu, lalu **5b Pembelian** |

**Paket yang akan dipasang** (versi dicek 08 Nov 2026 terhadap PHP 8.3.36 + Laravel 13.34):

| Paket | Versi | Fase | Catatan |
|---|---|---|---|
| `@fontsource/inter` (npm) | 5.3 | 1 | Font self-host, tanpa CDN |
| `blade-ui-kit/blade-heroicons` | 2.7 | 1 | Mendukung Laravel 13. Nama ikon bisa disimpan sebagai teks di `config/menu.php` |
| hapus `@tailwindcss/vite` (npm) | — | 1 | Tidak terpakai |
| `spatie/laravel-activitylog` | **4.12** (bukan 5.x) | 3 | 5.x butuh PHP 8.4 |
| `chart.js` (npm) | 4.6 | 4 | Grafik dashboard |
| `barryvdh/laravel-dompdf` | 3.1 | 5a | Ekspor PDF |
| `spatie/laravel-backup` | **tidak bisa dipasang** | 4 | Versi untuk Laravel 13 butuh PHP 8.4. Default: perintah backup sendiri dengan `mysqldump`. Bila PHP server di-upgrade ke 8.4, paket ini bisa dipakai |

### Diputuskan pemilik (08 Nov 2026, lanjutan)

5. **Pembelian tanpa PO tetap tersedia** di samping alur PO.
6. **Halaman profil pengguna dihidupkan lagi** (ubah nama, ganti password sendiri) lewat `ProfileController`, dengan tampilan baru di Fase 3. Kode sisa Breeze yang tidak dipakai (`welcome.blade.php`, `NewPasswordController`, komponen Breeze lama) dihapus di Fase 2.
7. **Modul HR diganti nama menjadi SDM, di tampilan dan di kode.** Ini pengecualian yang disengaja dari aturan "nama kode berbahasa Inggris", khusus untuk nama modul:
   - Tampilan: grup menu, judul halaman, dan dokumen memakai "SDM".
   - Kode: `routes/modules/sdm.php`, controller di `App\Http\Controllers\Sdm\*`, service di `App\Services\Sdm\*`, izin berawalan `sdm.` (misal `sdm.attendances.create`). Nama fitur dan aksi di dalamnya tetap bahasa Inggris.
   - Tetap: nama tabel database (`absensis`, `shifts`, `jadwals`, `jenis_cutis`, `tahap_persetujuans`, `cutis`, `persetujuan_cutis`), URL (`/absensi`, `/cuti`, ...), dan nama route (`attendances.*`, `leaves.*`, ...).
   - Tabel SDM baru di Fase 7 memakai bahasa Inggris (`employees`, `departments`, `positions`).
9. **Transfer stok langsung selesai** dalam satu langkah: stok keluar dari gudang asal dan masuk ke gudang tujuan sekaligus.

### Belum dijawab: dipakai saran sebagai default (bisa Anda ubah)

8. **Multi-cabang**: struktur disiapkan sesuai spesifikasi (tabel `branches`, pilihan cabang di topbar, gudang bisa ditautkan ke cabang). Laporan dan hak akses per cabang belum dibuat.
10. **Akses gudang per user**: belum dibatasi. Semua user dengan izin inventori melihat semua gudang. Pembatasan bisa ditambah lewat tabel `user_warehouse` bila diperlukan.

---

## 8. Rencana implementasi Fase 1–7 (disesuaikan dengan kondisi project)

Ukuran: **S** = kecil, **M** = sedang, **L** = besar, **XL** = sangat besar. Setiap fase diakhiri dengan `php artisan test`, `npm run build`, ringkasan perubahan, dan commit.

### Fase 0.5 — Persiapan (wajib sebelum Fase 1) · S
- ~~Commit pekerjaan HR yang belum di-commit sebagai commit sendiri.~~ Selesai (`02c4c97`, branch `redesign-tokoku`).
- ~~Pindahkan spesifikasi ke `docs/`.~~ Selesai.
- ~~Bereskan 11 test yang gagal.~~ Selesai: 372/374 lulus, 2 sisanya menunggu izin folder `storage/`.
- ~~Perbarui tabel hak akses di `README-TAHAP5.md`.~~ Selesai.
- ~~Salin "Aturan Proyek" dan ringkasan keputusan ke `CLAUDE.md`.~~ Selesai.
- (Oleh Anda) ~~set `APP_LOCALE=id`~~ (selesai), tinjau `APP_ENV`/`APP_DEBUG` di server, jalankan `sudo usermod -aG www-data hamori` untuk izin `storage/`.

### Fase 1 — Fondasi desain & komponen · M
**Status 08 Nov 2026: selesai, menunggu pengecekan pemilik.** Token warna (`primary`, `success`, `warning`, `danger`, `info`), font Inter, Heroicons, 24 komponen di `resources/views/components/ui`, dan halaman `/dev/ui-kit` sudah dibuat. 12 test baru (`UiKitTest`, `KomponenUiTest`). Halaman fitur belum diubah, kecuali font yang kini Inter di semua halaman. Paket baru baru aktif setelah `sudo -u www-data php artisan package:discover` (risiko #20).

- **Tetap Tailwind v3.4 + PostCSS.** Tambah token di `tailwind.config.js`: `primary` (indigo `#4F46E5`), `success`, `warning`, `danger`, `info`, netral slate. Token `brand`/`accent` lama **dibiarkan dulu** agar halaman lama tidak berubah.
- Pasang `@fontsource/inter` (font Inter, self-host) dan `blade-ui-kit/blade-heroicons`. Hapus `@tailwindcss/vite`. Komponen `x-ui.icon name="..."` membungkus Heroicons supaya nama ikon bisa dibaca dari config.
- Blade components di `resources/views/components/ui/`: button, input, select, textarea, checkbox, badge, card, stat-card, table (sticky header, sort, empty state, pagination), modal, dropdown, tabs, alert/toast, confirm-dialog, breadcrumb, page-header, form-section, filter-bar, skeleton, icon.
  - `confirm-dialog` dan `toast` menggantikan `partials/confirm-delete` dan `partials/toast` dengan **event Alpine yang sama** (`konfirmasi-hapus`), supaya halaman lama tetap bekerja.
  - `x-ui.input` mengambil alih peran `x-isian` (biarkan `x-isian` sampai tidak dipakai lagi).
- Halaman `/dev/ui-kit`: hanya terdaftar bila `app()->isLocal()` **dan** dilindungi `auth` + Super Admin (risiko #16).
- Dark mode: siapkan strategi `class` di config, tetapi tunda implementasi penuh.
- Test: akses `/dev/ui-kit` (tamu ditolak, non-admin ditolak, tidak ada di production).
- **Belum mengubah halaman fitur.**

### Fase 2 — Layout utama & menu dinamis · L
**Status 08 Nov 2026: selesai, menunggu pengecekan pemilik.**
- Layout aplikasi baru (sidebar 260px yang bisa diciutkan ke ikon, drawer di layar kecil, topbar dengan breadcrumb, pencarian global, pilihan cabang, notifikasi stok, menu profil) dan layout Kasir layar penuh.
- `config/menu.php` memuat seluruh Struktur Menu dengan status enabled/coming_soon/hidden. Menu SDM yang sudah berjalan tetap aktif; yang belum ada (Karyawan, Departemen & Jabatan, Penggajian) serta seluruh Akuntansi = Segera Hadir.
- Halaman "Segera Hadir" (`/segera-hadir/{key}`), pencarian global (`/pencarian`, JSON, kasir hanya menemukan penjualannya sendiri), notifikasi stok (`/notifikasi`, JSON, diambil saat lonceng dibuka).
- Route dipecah ke `routes/modules/*.php`: URL, nama route, dan middleware tidak berubah (dibandingkan lewat `route:list`). Controller dan service SDM pindah ke namespace `Sdm`.
- Halaman lama otomatis memakai layout baru tanpa perubahan logika. Kelas CSS lama (`.tombol-*`, `.isian`, `.tabel`, `.badge-*`) dan warna `stone` dipetakan ke token baru agar serasi. Partial badge status memakai `x-ui.badge`.
- Dihapus: partial toast/konfirmasi lama, `welcome.blade.php`, `NewPasswordController`, dan komponen Breeze yang tidak dipakai. Komponen yang dipakai view profil dipertahankan untuk Fase 3.
- 17 test baru (`MenuSidebarTest`, `HalamanSegeraHadirTest`, `PencarianGlobalTest`, `NotifikasiStokTest`); seluruh suite 403/403 lulus.
- Belum: memindahkan tombol aksi tiap halaman ke slot `actions` (dikerjakan saat modulnya di-redesign), dark mode.

- `layouts/app` baru: sidebar 260px yang bisa diciutkan ke ikon (status disimpan di `localStorage`), grup dengan judul kecil, submenu buka-tutup, menu aktif ter-highlight, drawer di mobile. Topbar: tombol collapse, breadcrumb, pencarian global (versi awal: produk, pelanggan, nomor transaksi), notifikasi (awal: jumlah stok menipis), pilihan cabang (placeholder nonaktif sampai Fase 4), menu profil + Keluar.
- `layouts/pos` baru (layar penuh, tanpa sidebar). Untuk sementara Kasir dipindah ke sini **tanpa mengubah isi** (redesign penuh di Fase 6).
- `<x-app-layout>` mendapat slot tambahan `description` dan `actions`. Karena 41 halaman sudah memakai komponen ini, pergantian layout cukup di satu tempat. Lalu rapikan header tiap halaman: pindahkan tombol aksi ke slot `actions`.
- `config/menu.php` dibuat ulang menjadi pohon `grup → menu → submenu` dengan `label`, `icon`, `route`, `active`, `permission`, `module`, dan `status` (`enabled` / `coming_soon` / `hidden`). Pemetaan menu lama:
  - Kasir: Transaksi Baru (`pos.index`), Riwayat (`sales.index`), Pelanggan (`customers.index`). Sesi Kasir dan Retur = `coming_soon`.
  - Inventori: Produk, Kategori, Satuan, Stok (`stock.index`), **Kartu Stok** (`stock.movements`), Stok Opname, Resep/BOM. Gudang/Lokasi dan Mutasi/Transfer = `coming_soon` (dibangun di Fase 5a).
  - Pembelian: Supplier, Pembelian (`purchases.index`). PO, Penerimaan, Retur, dan Hutang = `coming_soon`.
  - SDM (dulu HR): Absensi, Jadwal, Shift Kerja, Cuti & Izin, Jenis Cuti = `enabled`. Karyawan, Departemen & Jabatan, Penggajian = `coming_soon`.
  - Akuntansi: semua `coming_soon`.
- `App\Support\Menu` diperluas untuk pohon menu, filter izin, dan status. Grup hardcode di `layouts/app.blade.php` dihapus. Menu `coming_soon` diarahkan ke satu route placeholder `segera-hadir/{modul}` (bukan mendaftarkan nama route palsu yang nanti bentrok dengan route asli).
- Route dipecah ke `routes/modules/{pos,inventory,purchasing,sdm,reports,users,settings}.php` dan di-include dari `web.php`. **URL dan nama route tidak berubah**: bandingkan `route:list` sebelum dan sesudah. Pemindahan controller ke subfolder modul ditunda ke fase modul masing-masing, **kecuali SDM**.
- SDM dipindah di fase ini karena tidak punya fase redesign sendiri sebelum Fase 7: controller ke `App\Http\Controllers\Sdm\*` dan service ke `App\Services\Sdm\*`, termasuk import di test. Nama route dan URL tetap. Test SDM (Absensi, Jadwal, Shift, Cuti, Jenis Cuti) harus tetap hijau.
- Hapus kode sisa Breeze yang tidak dipakai: `welcome.blade.php`, `NewPasswordController`, dan komponen Breeze lama (`x-text-input`, `x-primary-button`, `x-nav-link`, dll.). `ProfileController` dan view profile **dipertahankan** untuk Fase 3.
- Test: filter menu per peran, item `coming_soon`, menu aktif, layout tampil untuk tiap peran.

### Fase 3 — Pengguna & Hak Akses · L
**Status 09 Nov 2026: selesai, menunggu pengecekan pemilik.**
- Katalog izin `config/permission-matrix.php` (165 izin, 9 modul termasuk Akuntansi & SDM, format `{modul}.{fitur}.{aksi}`), dibaca lewat `App\Support\PermissionCatalog`.
- Migration data mengganti 31 izin lama ke format baru tanpa mengubah hak akses yang berlaku dan tanpa menyentuh pengguna (diuji di `MigrasiIzinLamaTest`).
- `RolePermissionSeeder`: 8 peran bawaan. Menjalankan ulang seeder tidak menimpa perubahan dari halaman Hak Akses; hanya izin yang baru masuk katalog yang diberikan ke peran bawaan.
- Semua route yang butuh login dijaga `permission:` (kecuali dashboard, pencarian, segera-hadir, ui-kit, profil, logout; dijaga `ProteksiRouteTest`).
- Halaman: Pengguna (CRUD, aktif/nonaktif, reset kata sandi), Peran (CRUD peran buatan sendiri), Hak Akses (matriks per peran), Log Aktivitas, Profil Saya.
- Log aktivitas (`spatie/laravel-activitylog` 4.12): login/logout/login gagal, perubahan 9 data master, pergantian peran, reset kata sandi, perubahan peran & hak akses, pembatalan penjualan/pembelian, perubahan profil toko. Log lebih dari 365 hari dihapus oleh jadwal harian (butuh scheduler aktif di server).
- Perubahan perilaku: menu Akuntansi kini milik Akuntan & Pemilik (sebelumnya sementara memakai izin laporan, sehingga Admin ikut melihat). Form ubah pengguna tidak lagi berisi kata sandi; gantinya tombol Reset kata sandi.
- Seluruh suite 439/439 lulus.

- spatie/laravel-permission **sudah terpasang**, tidak perlu instal. Fokusnya **migrasi**:
  - Buat daftar izin baru `{modul}.{fitur}.{aksi}` untuk semua modul (termasuk Akuntansi & SDM) dalam satu kelas konstanta atau enum. Modul SDM berawalan `sdm.` (misal `record-attendance` menjadi `sdm.attendances.record`, `manage-leave-types` menjadi `sdm.leave-types.manage`).
  - Migration data yang **mengganti nama** baris izin lama ke nama baru (id tetap, jadi relasi peran dan user utuh). Izin baru ditambahkan, tidak ada yang dihapus.
  - Ganti semua `can:...`, `@can`, Policy, dan test ke nama baru dalam satu langkah. Test per peran menjadi jaring pengaman.
- 8 peran bawaan lewat seeder. `RoleName` hanya dipertahankan untuk peran sistem. Cast `peran` di modul Cuti diubah ke string, validasi peran memakai tabel `roles` (risiko #6).
- Matriks bawaan: Admin **memegang** izin Kasir (`pos.transactions.create`). Staf Gudang memegang izin inventori dan gudang. Purchasing memegang izin pembelian. Akuntan dan HRD disiapkan untuk Fase 7.
- Halaman: Pengguna (sudah ada, ditambah tombol reset password dan redesign), Peran (CRUD; Super Admin tidak bisa dihapus atau diubah), Matriks Hak Akses (baris = fitur, kolom = aksi, checkbox per peran, dikelompokkan per modul), Profil Saya (ubah nama, ganti password sendiri, memakai `ProfileController` yang sudah ada).
- Log aktivitas dengan `spatie/laravel-activitylog` **4.12**: login/logout, CRUD master data, void, perubahan pengaturan, perubahan izin. Ditambah halaman Log Aktivitas.
- Test: `HakAksesTest` diperbarui, test matriks (simpan dan cabut izin), test Cuti dengan peran dinamis.

### Fase 4 — Pengaturan & Dashboard · L
**Status 09 Nov 2026: selesai, menunggu pengecekan pemilik.**
- Helper `setting('key', $default)` untuk membaca dan `setting([...])` untuk menyimpan, lewat `App\Support\SettingStore`. Semua nilai dibaca sekali lalu di-cache sampai ada perubahan.
- Halaman Pengaturan baru:
  - Profil Toko: ditambah logo, NPWP, dan email.
  - Cabang: tabel `branches` dengan "Cabang Utama" bawaan, plus pilihan cabang aktif di topbar.
  - Pajak: pengaturan PPN, belum dihitung di kasir sampai Fase 6.
  - Metode Pembayaran: tabel `payment_methods` berisi nama, aktif/nonaktif, dan urutan di kasir.
  - Struk & Printer: lebar kertas, footer, logo/NPWP di struk, cetak otomatis, dan pratinjau langsung.
  - Penomoran Dokumen.
  - Backup.
- Penomoran: `DocumentNumberService` dan tabel `document_sequences` untuk 6 jenis dokumen (penjualan, pembelian, pembayaran hutang, penerimaan piutang, opname, cuti). Pola bawaan sama dengan format lama, jadi nomor tidak berubah sampai polanya diganti. Nomor yang sudah terpakai dilewati. Kode master data (SUP-, CUS-, PRD-) tetap memakai `KodeService`.
- Backup: perintah `php artisan backup:database` menjalankan mysqldump + gzip dan menyimpan 14 berkas terbaru di `storage/app/private/backups`. Dijadwalkan pukul 01:30. Halaman Backup untuk membuat dan mengunduh berkas; izinnya hanya untuk Pemilik dan Super Admin.
- Dashboard:
  - KPI: omzet hari ini dibanding kemarin, jumlah transaksi, stok menipis, dan hutang jatuh tempo (plus yang jatuh tempo 7 hari ke depan).
  - Grafik omzet 7/30 hari dengan `chart.js`, yang hanya dimuat di dashboard.
  - Produk terlaris 7/30 hari, transaksi terakhir, dan daftar stok menipis.
  - Setiap bagian tampil sesuai izin. Kasir hanya melihat angka penjualannya sendiri.
- Perubahan perilaku:
  - Lebar kertas dan footer struk pindah dari Profil Toko ke Struk & Printer. Nilai yang tersimpan tetap.
  - Nilai cetak otomatis dari `.env` hanya menjadi bawaan sampai disimpan di Struk & Printer.
  - Uang muka (DP) penjualan piutang hanya bisa memakai metode yang aktif di kasir.
- Seluruh suite 537/537 lulus.

- Helper `setting('key', $default)` di atas `Setting::ambil()` dengan cache yang dihapus saat data disimpan (cache driver `array` saat test, sesuai catatan di model `Setting`). `StoreProfileService` tetap dipakai.
- Profil Toko: tambah logo, NPWP, email.
- Cabang: tabel `branches` + "Cabang Utama" bawaan + pilihan cabang di topbar (disimpan di session). Stok tidak dipisah per cabang, melainkan per **gudang** (Fase 5a), dan gudang bisa ditautkan ke cabang.
- Pajak: pengaturan PPN (tarif, harga termasuk/belum termasuk pajak). Dipakai di Kasir pada Fase 6.
- Metode Pembayaran: tabel `payment_methods` (aktif/nonaktif, urutan, label) yang dipetakan ke kode enum `PaymentMethod` yang sudah ada. Logika khusus `cash` (kembalian) dan `receivable` (piutang) tetap di kode.
- Struk & Printer: halaman preview memakai view `sales/receipt` dengan data contoh. Opsi cetak otomatis dipindah dari `.env` ke `settings`.
- Penomoran Dokumen: `DocumentNumberService` membaca pola dari settings (misal `INV/{YYYY}/{MM}/{####}`) dan menggantikan awalan hardcode di `KodeService`. Hanya berlaku untuk dokumen **baru**; nomor lama tidak diubah.
- Backup: `spatie/laravel-backup` tidak bisa dipasang di PHP 8.3 (risiko #19). Gantinya perintah `php artisan backup:database` yang memanggil `mysqldump` dan menyimpan file terkompresi ke `storage/app/backups`, dijadwalkan harian lewat scheduler. Halaman Backup (khusus Super Admin) untuk melihat daftar, membuat, dan mengunduh. Kredensial database dibaca dari config, tidak pernah ditampilkan.
- Dashboard: stat-card (penjualan hari ini, jumlah transaksi, produk stok menipis, hutang jatuh tempo dari `purchases.due_date` + `balance`), grafik 7/30 hari dengan `chart.js`, transaksi terakhir, produk terlaris. Agregasi memakai ulang `LaporanPenjualan`. Kartu yang tampil menyesuaikan izin user. Stok menipis dihitung dari total stok dulu; setelah Fase 5a bisa per gudang.
- Test: helper setting, penomoran (format dan urutan), data dashboard per peran.

### Fase 5a — Multi-gudang & Inventori · XL
Dikerjakan sebelum 5b dan Fase 6 karena mengubah `StockService`, yang dipakai penjualan dan pembelian.

**Data (semua tambahan, tidak ada yang dihapus)**
- `warehouses`: kode, nama, alamat, `branch_id` nullable, `is_default` (tepat satu), `is_active`, soft delete. Seeder membuat "Gudang Utama" sebagai bawaan.
- `warehouse_stocks`: `warehouse_id`, `product_id`, `quantity` decimal(15,3), unik per pasangan. `min_stock` tetap per produk dulu.
- `stock_movements.warehouse_id`: diisi untuk semua mutasi baru. Mutasi lama diisi Gudang Utama (saat ini 0 baris).
- `stock_transfers` + `stock_transfer_items`: nomor, gudang asal, gudang tujuan, tanggal, catatan, user, status (draft → selesai / dibatalkan).
- Kolom `warehouse_id` di `stock_opnames`, `sales`, dan `purchases`, supaya pembatalan mengembalikan stok ke gudang yang benar.
- Migration data: isi `warehouse_stocks` Gudang Utama dari `products.stock` saat ini (4 produk).

**`StockService`**
- `catat()` menerima gudang. Selama masa peralihan, pemanggil lama otomatis memakai gudang bawaan.
- Dalam satu transaksi database: kunci baris `products` lalu `warehouse_stocks` (urutan tetap berdasarkan `product_id` agar tidak deadlock), tolak stok minus **per gudang**, ubah stok gudang dan `products.stock` (total) bersamaan, lalu tulis kartu stok beserta `warehouse_id`.
- `stock_before`/`stock_after` di kartu stok = stok gudang tersebut. Kartu stok bisa difilter per gudang atau semua gudang.
- `products.stock` tetap total semua gudang, sehingga pencarian Kasir, status stok, dan laporan lama tetap jalan.
- Enum `StockMovementType` ditambah `TransferOut` dan `TransferIn`. Satu transfer = dua mutasi dalam satu transaksi, dan total `products.stock` tidak berubah.
- Perintah `stok:periksa` diperluas: per gudang (stok gudang = jumlah mutasinya) dan total (`products.stock` = jumlah semua gudang).

**Pemakai `StockService` yang ikut disesuaikan**
- Penjualan: memakai Gudang Utama sampai Fase 6 (lalu gudang dari sesi kasir).
- Pembelian: pilih gudang tujuan.
- Penyesuaian stok: pilih gudang.
- Stok opname: per gudang.
- Pembatalan penjualan dan pembelian: stok kembali ke gudang asal transaksi.

**Halaman**
- Gudang/Lokasi: CRUD, pilih gudang bawaan. Gudang hanya bisa dinonaktifkan bila stoknya 0.
- Mutasi/Transfer Stok: daftar, buat, detail, cetak surat jalan (PDF dengan `barryvdh/laravel-dompdf`).
- Stok: tabel produk × gudang dengan filter gudang.
- Redesign Produk, Kategori/Satuan (`master/index`), Kartu Stok, Stok Opname (draft → final, per gudang), Resep.
- Laporan stok per gudang dan nilai persediaan, ekspor Excel dan PDF.
- Tabel daftar: pencarian, filter, sort, pagination, ekspor.

**Lainnya**
- Izin baru `inventory.warehouses.*` dan `inventory.transfers.*`, diberikan ke Admin dan Staf Gudang.
- Controller dipindah ke `Controllers/Inventory/*` dengan nama route tetap.
- Test: `StockServiceTest` diperluas (stok minus per gudang, total selalu sinkron, penguncian), transfer, opname per gudang, `stok:periksa`. Semua test penjualan dan pembelian lama harus tetap hijau.

### Fase 5b — Pembelian · L
- Tabel baru `purchase_orders` + `purchase_order_items` (draft → disetujui → diterima sebagian → selesai/dibatalkan). Persetujuan PO memakai izin `purchasing.orders.approve`.
- `goods_receipts` + `goods_receipt_items` (bisa parsial, dengan **gudang tujuan**) yang menambah stok lewat `StockService`.
- Tabel `purchases` yang ada tetap menjadi faktur/hutang dan diberi `purchase_order_id` nullable. Pembelian langsung tanpa PO tetap bisa.
- Samakan arti `subtotal` dengan penjualan: tambah kolom `item_discount`, `subtotal` = sebelum diskon per baris (risiko #22).
- Hutang Supplier: halaman daftar hutang + bayar, memakai `PaymentService::bayarPembelian()` yang sudah teruji.
- Retur Pembelian: keluar dari gudang tertentu, memakai tipe mutasi `purchase_return` yang sudah ada.
- Event: `GoodsReceived`, `PurchaseVoided`, `SupplierPaymentRecorded` (belum ada listener; disiapkan untuk Akuntansi).
- Tabel daftar: pencarian, filter, sort, pagination, ekspor Excel dan PDF. Cetak PO sebagai PDF.
- Laporan Pembelian (per supplier, per produk, per periode).
- Controller dipindah ke `Controllers/Purchasing/*` dengan nama route tetap.
- Test: alur PO → penerimaan parsial ke gudang → stok → hutang, event dikirim, retur, ekspor.

### Fase 6 — Kasir (POS) · XL
- Pindahkan JS `kasir()` ke `resources/js/pos/` **tanpa mengubah logika** (satu commit), baru redesign tampilan: kiri pencarian + scan + grid kategori, kanan keranjang, diskon, pajak, total besar, tombol bayar.
- Shortcut baru: F2 cari, F8 bayar, Esc batal. Diskon dan Tahan pindah ke tombol lain, legenda tampil di layar. Input scan otomatis fokus.
- Split payment (lebih dari satu metode): tabel `payments` sudah polimorfik dan bisa banyak baris per penjualan, jadi yang berubah `SaleService` dan UI. `sales.payment_method` menyimpan metode utama atau nilai "campuran".
- Pajak penjualan: kolom aditif `tax_rate` dan `tax` di `sales`.
- Sesi Kasir: tabel **`cash_sessions`** (bukan `shifts`) berisi **gudang penjualan**, modal awal, total per metode, uang fisik saat tutup, dan selisih. Ditambah kolom `sales.cash_session_id` nullable. Opsi "wajib buka sesi sebelum menjual" diatur lewat pengaturan.
- Gudang penjualan: diambil dari sesi kasir, atau dari gudang bawaan cabang aktif bila tanpa sesi. Pencarian produk di Kasir menampilkan stok gudang tersebut, dan penolakan stok kurang dihitung per gudang.
- Retur Penjualan: `sale_returns` + item, stok kembali lewat `StockService`, pengembalian dana. Ini juga menjadi jalan keluar untuk transaksi yang tidak bisa di-void (sudah ada pelunasan piutang).
- Piutang Pelanggan: halaman pelunasan, memakai `PaymentService::terimaPenjualan()` yang sudah ada.
- Keputusan BOM: apakah penjualan produk ber-resep ikut mengurangi stok bahan baku?
- Event: `SaleCompleted`, `SaleVoided`, `SaleReturned`, dikirim setelah transaksi database selesai (*after commit*).
- Uji manual di tablet 1024px dan dengan scanner barcode. Halaman struk thermal tetap mandiri.
- Test: `PenjualanTest`, `HeldSaleTest`, `PembayaranTest` tetap hijau, ditambah split payment, sesi kasir, retur, dan event.

### Fase 7 — Kerangka Akuntansi & SDM · L
- Akuntansi: `accounts` (COA bertingkat), `journals` + `journal_lines` (debit = kredit divalidasi di service), seeder COA retail Indonesia.
  - Listener `SaleCompleted` membuat jurnal penjualan + HPP dari `sale_items.cost_price` yang sudah tersimpan.
  - Listener `GoodsReceived` membuat jurnal persediaan + hutang.
  - Listener void/retur membuat jurnal pembalik.
  - Bisa dimatikan lewat `setting('accounting.auto_journal')`.
  - Halaman: COA, Jurnal Umum (daftar + form).
- SDM: `departments`, `positions`, `employees` (`user_id` nullable unik). Model dan controller baru di namespace `Sdm`. Absensi, Jadwal, dan Cuti yang sudah ada tetap memakai `user_id` dan ditautkan lewat `employees.user_id`. Halaman daftar & form karyawan. Penggajian (event `PayrollPosted`) cukup dirancang.
- Ubah status menu menjadi `enabled` hanya untuk halaman yang sudah jadi.
- Tulis `docs/ROADMAP.md`.
- Test: validasi jurnal seimbang, listener membuat jurnal yang benar, listener tidak jalan bila dimatikan.

### Urutan dan ketergantungan
```
0.5 Persiapan → 1 Fondasi UI → 2 Layout & Menu → 3 Hak Akses → 4 Pengaturan & Dashboard
                                                                        ↓
                    5a Multi-gudang & Inventori → 5b Pembelian → 6 Kasir → 7 Akuntansi & SDM
```
Fase 3 sebaiknya selesai sebelum Fase 5a dan 6 agar izin modul baru langsung memakai format baru. Fase 4 (cabang, penomoran, pajak, metode bayar) dibutuhkan oleh Fase 5a, 5b, dan 6. Fase 5a harus selesai sebelum 5b dan 6 karena keduanya memakai `StockService` versi multi-gudang.
