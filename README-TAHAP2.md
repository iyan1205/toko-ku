# Tahap 2 — Master Data: Produk, Kategori, Satuan, Supplier, Pelanggan

Paket ini **ditimpakan di atas proyek Tahap 1** (hanya berisi file baru dan file yang berubah).

## 1. Struktur database

| Tabel | Kolom utama | Catatan |
|---|---|---|
| `categories` | `name` (unik), `description` | Hapus ditolak bila masih dipakai produk (termasuk produk yang sudah dihapus) |
| `units` | `name` (unik), `description` | Nama disimpan huruf besar (PCS, KG, ...) |
| `products` | `sku` (unik), `barcode` (unik, boleh kosong), `name`, `category_id`, `unit_id`, `purchase_price`, `selling_price`, `stock`, `min_stock`, `discount_percent`, `photo`, `description`, `is_active`, `deleted_at` | Uang `decimal(15,2)`, stok `decimal(15,3)` (untuk KG/LITER). FK `restrictOnDelete`. Soft delete |
| `suppliers` | `code` (unik, otomatis SUP-0001), `name`, `contact_name`, `phone`, `email`, `address`, `npwp`, `note`, `deleted_at` | Soft delete |
| `customers` | `code` (unik, otomatis CUS-0001), `name`, `phone`, `email`, `address`, `note`, `receivable_balance`, `is_default`, `deleted_at` | Baris "Umum" (`code = UMUM`) dibuat seeder; soft delete |

## 2. Memasang

```bash
# di folder proyek Tahap 1 Anda
composer require maatwebsite/excel        # versi 3.1.68+ mendukung Laravel 13
cp -R ../pos-toko-tahap2/. .              # timpa (ganti path sesuai lokasi zip diekstrak)
php artisan storage:link                  # agar foto produk bisa tampil
php artisan migrate
php artisan db:seed --class=MasterDataSeeder   # kategori, satuan, pelanggan Umum, produk contoh
npm run build                              # atau biarkan npm run dev berjalan
php artisan test
```

Prasyarat tambahan: ekstensi PHP **gd** dan **zip** aktif (dibutuhkan Laravel Excel / PhpSpreadsheet, dan tes unggah foto).
`MasterDataSeeder` aman dijalankan berulang kali. Produk contoh (Ayam Geprek, Nasi Putih, Teh Botol, Sambal Bawang) tidak dibuat di production.

### File Tahap 1 yang ditimpa

| File | Perubahan |
|---|---|
| `routes/web.php` | Menambah route Produk, Kategori, Satuan, Supplier, Pelanggan |
| `database/seeders/DatabaseSeeder.php` | Memanggil `MasterDataSeeder` |
| `app/Support/Format.php` | Menambah `Format::jumlah()` (stok tanpa nol di belakang koma) |
| `lang/id/validation.php` | Menambah pesan `file` dan `uploaded` |
| `tests/TestCase.php` | Memanggil `withoutVite()` agar tes tidak bergantung pada `npm run build` |

Menu sidebar tidak perlu diubah: Produk, Kategori, Satuan, Supplier, dan Pelanggan muncul otomatis karena route-nya sekarang ada.

## 3. Fitur dan aturan

**Produk**
- Tambah, ubah, hapus (soft delete), detail, pencarian (nama, SKU, barcode), filter kategori/stok/status.
- SKU boleh dikosongkan, lalu dibuat otomatis `PRD-00001`. SKU dan barcode unik, termasuk terhadap produk yang sudah dihapus.
- Kolom barcode mengabaikan tombol Enter, jadi scanner tidak mengirim formulir secara tidak sengaja. Scanner di kolom pencarian langsung mencari produk.
- Foto: JPG/PNG/WEBP, maksimal 2 MB, disimpan di `storage/app/public/produk`. Foto lama dihapus saat diganti.
- **Stok tidak bisa diisi dari formulir** (`stock` tidak ada di `$fillable`). Aturan "setiap perubahan stok harus tercatat" ditegakkan mulai sekarang; stok awal diisi lewat Pembelian (Tahap 4) atau Stok Opname (Tahap 3).
- Status stok: HABIS bila stok ≤ 0, MENIPIS bila stok ≤ stok minimum, selain itu AMAN.
- **Kasir** boleh melihat daftar dan detail produk, tetapi tidak melihat harga beli dan laba, dan tidak bisa menambah, mengubah, atau menghapus.
- **Impor Excel/CSV:** unduh templat, isi, unggah. SKU yang sudah ada diperbarui, SKU baru atau kosong dibuat. Kategori dan satuan harus sudah ada. Satu baris salah membatalkan seluruh impor (semua-atau-tidak-sama-sekali) dan menampilkan daftar kesalahan per baris. Kolom Stok diabaikan.
- **Ekspor Excel** mengikuti filter yang sedang aktif (hanya yang berhak mengelola produk).

**Kategori dan Satuan:** satu halaman dengan modal tambah/ubah. Dihapus hanya bila tidak dipakai produk.

**Supplier:** kode otomatis, CRUD, soft delete. Riwayat pembelian, total pembelian, dan hutang ditampilkan setelah Tahap 4 dan 7.

**Pelanggan:** kode otomatis, CRUD, soft delete. "Umum" bawaan sistem tidak bisa diubah atau dihapus. Pelanggan dengan piutang belum lunas tidak bisa dihapus. Saldo piutang tidak bisa diisi manual; hanya modul piutang (Tahap 7) yang mengubahnya, supaya selalu ada riwayat.

## 4. Hak akses

| Menu | Super Admin | Admin | Kasir |
|---|:-:|:-:|:-:|
| Produk (lihat daftar dan detail, tanpa harga beli) | ✓ | ✓ | ✓ |
| Produk (tambah, ubah, hapus, impor, ekspor, harga beli) | ✓ | ✓ | – |
| Kategori (`manage-categories`) | ✓ | ✓ | – |
| Satuan (`manage-products`) | ✓ | ✓ | – |
| Supplier (`manage-suppliers`) | ✓ | ✓ | – |
| Pelanggan (`manage-customers`) | ✓ | ✓ | – |

Kasir belum bisa membuka daftar pelanggan. Pemilihan pelanggan di layar kasir ditangani pada Tahap 5.

## 5. Cek manual

1. Login `admin@example.com` → menu Produk, Kategori, Satuan, Supplier, Pelanggan muncul.
2. Kategori → tambah "Snack" lewat modal; coba tambah lagi dengan nama sama → modal terbuka kembali dengan pesan error.
3. Produk → tambah tanpa SKU → SKU `PRD-00001` (atau lanjutan dari produk contoh), stok 0.
4. Produk → Ekspor Excel, ubah harga di Excel, Impor Excel → harga berubah, stok tidak.
5. Impor berkas dengan kategori salah ketik → seluruh impor dibatalkan, daftar kesalahan tampil.
6. Login `kasir@example.com` → Produk terlihat tanpa kolom Harga beli dan tanpa tombol tambah; `/kategori` → 403.
7. Pelanggan → "Umum" ada di baris pertama dan tidak punya tombol Ubah/Hapus.

Tahap 2 selesai bila langkah di atas berjalan dan `php artisan test` hijau.
Berikutnya: **Tahap 3 — Inventory** (tabel `stock_movements`, layanan stok, stok masuk/keluar, Stok Opname).
