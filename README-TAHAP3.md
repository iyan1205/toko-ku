# Tahap 3 — Inventory: Stok, Mutasi Stok, Penyesuaian, Stok Opname

Paket ini **ditimpakan di atas proyek Tahap 2** (hanya file baru dan file yang berubah). Tidak ada paket Composer baru.

## 1. Struktur database

| Tabel | Kolom utama | Catatan |
|---|---|---|
| `stock_movements` | `product_id`, `type`, `reference_type`, `reference_id`, `quantity`, `stock_before`, `stock_after`, `note`, `user_id`, `created_at` | Buku besar stok. **Tambah-saja**: tidak ada `updated_at`, model menolak update/hapus |
| `stock_opnames` | `number` (unik, `OPN-20261005-0001`), `opname_date`, `note`, `user_id` | Tidak ada soft delete dan tidak ada tombol hapus |
| `stock_opname_items` | `stock_opname_id`, `product_id`, `system_stock`, `physical_stock`, `difference`, `reason` | Unik per (opname, produk) |

`quantity` **bertanda**: positif = masuk, negatif = keluar, dan selalu berlaku `stock_after = stock_before + quantity`.
Semua angka stok `decimal(15,3)`. Semua foreign key `restrictOnDelete`.

Jenis mutasi (`StockMovementType`): Pembelian, Penjualan, Retur Pembelian, Retur Penjualan, Penyesuaian Stok, Barang Rusak, Barang Hilang.
Pembelian, penjualan, dan retur dibuat oleh modul masing-masing pada tahap berikutnya.

## 2. Memasang

```bash
cp -R ../pos-toko-tahap3/. .       # timpa di atas proyek Tahap 2
php artisan migrate
php artisan test
php artisan stok:periksa           # harus mengatakan semua stok konsisten
```

Opsional di `.env` (default sudah aman): `POS_ALLOW_NEGATIVE_STOCK=false`.

### File Tahap 2 yang ditimpa
`routes/web.php`, `app/Models/Product.php` (relasi mutasi), `app/Providers/AppServiceProvider.php` (morph map),
`app/Services/KodeService.php` (mendukung model tanpa soft delete), `app/Http/Controllers/ProductController.php`,
`resources/views/products/show.blade.php` (menampilkan mutasi terakhir).

## 3. Cara kerja stok

**Satu-satunya pintu** untuk mengubah stok adalah `StockService::catat()`. Di dalam satu transaksi database, ia:
1. mengunci baris produk (`lockForUpdate`) agar dua kasir tidak saling menimpa,
2. menghitung stok baru dari nilai terkini di database, bukan dari data di layar,
3. menolak bila hasilnya minus: pesan **"Stok tidak mencukupi."** diikuti nama produk, stok tersedia, dan jumlah diminta,
4. menulis `products.stock` dan baris `stock_movements` bersama-sama. Bila salah satu gagal, keduanya dibatalkan.

`stock` tidak ada di `$fillable` Product, jadi formulir dan impor tidak bisa mengubahnya. Stok minus hanya bisa diizinkan lewat `config/pos.php` (nanti dipindah ke menu Pengaturan).

**Penyesuaian stok** (`Stok → Penyesuaian stok`): jenis Penyesuaian (tambah atau kurangi), Barang rusak, Barang hilang. Alasan wajib. Dipakai juga untuk **stok awal** produk baru.

**Stok opname** (`Stok Opname → Opname baru`):
1. Pilih tanggal (tidak boleh melewati hari ini), filter produk per kategori/pencarian bila perlu.
2. Isi stok fisik. Selisih tampil langsung. Produk yang dikosongkan dilewati (boleh opname per rak).
3. Selisih ≠ 0 **wajib punya alasan**.
4. Saat disimpan, setiap produk berselisih menghasilkan satu mutasi `Penyesuaian Stok` yang merujuk nomor opname. Produk yang cocok tidak menghasilkan mutasi.
5. **Pengaman stok basi:** bila stok sistem berubah sejak halaman dibuka (misalnya ada penjualan saat menghitung), opname ditolak dengan pesan produk mana yang berubah, supaya hitungan fisik tidak dibandingkan dengan angka yang salah.

**Pemeriksa konsistensi:** `php artisan stok:periksa` membandingkan stok setiap produk dengan jumlah semua mutasinya dan saldo mutasi terakhir. Hanya membaca, tidak pernah memperbaiki sendiri, dan keluar dengan kode 1 bila ada yang tidak cocok. Cocok dijadwalkan harian.

## 4. Hak akses

| Menu | Super Admin | Admin | Kasir |
|---|:-:|:-:|:-:|
| Stok (ringkasan, status stok) | ✓ | ✓ | ✓ (tanpa nilai persediaan) |
| Nilai persediaan, Penyesuaian, Riwayat mutasi | ✓ | ✓ | – |
| Stok Opname | ✓ | ✓ | – |

## 5. Keputusan desain

- **Opname memakai jenis `Penyesuaian Stok`** sesuai contoh di spesifikasi ("sistem harus membuat stock movement: ADJUSTMENT"), dan dibedakan lewat rujukan ke nomor opname. Jenis terpisah "Stok Opname" sengaja tidak dibuat. Bila ingin terpisah, tambahkan satu case di `StockMovementType` dan ganti satu baris di `StockOpnameService`.
- **Stok awal** lewat Penyesuaian Stok, bukan jenis tersendiri, supaya daftar jenis tetap sesuai spesifikasi.
- **Opname langsung diterapkan saat disimpan** (tidak ada status draft), sesuai "Simpan opname" di spesifikasi.
- **Riwayat tidak bisa dikoreksi dengan edit.** Kesalahan diperbaiki dengan mutasi baru, sehingga jejaknya utuh.

## 6. Cek manual

1. Login `admin@example.com` → Stok → Penyesuaian stok → "Ayam Geprek", Penyesuaian, Tambah, 100, alasan "Stok awal" → stok 100 dan muncul di Riwayat mutasi.
2. Coba Kurangi 500 → ditolak "Stok tidak mencukupi." dan stok tetap 100.
3. Stok Opname → Opname baru → isi fisik Ayam Geprek 97, kosongkan alasan → ditolak; isi alasan → tersimpan sebagai `OPN-…-0001`. Riwayat mutasi menampilkan −3 dengan tautan ke opname.
4. Buka halaman opname di dua tab. Simpan di tab 1 untuk Ayam Geprek, lalu simpan di tab 2 → ditolak karena stok berubah.
5. Login `kasir@example.com` → Stok terlihat tanpa nilai persediaan dan tanpa tombol penyesuaian; `/stok-opname` dan `/stok/mutasi` → 403.
6. Jalankan `php artisan stok:periksa` → semua konsisten.

Berikutnya: **Tahap 4 — Pembelian** (faktur supplier, stok otomatis bertambah lewat `StockService`, hutang awal).
