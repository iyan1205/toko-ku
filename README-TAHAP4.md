# Tahap 4 — Pembelian

Paket ini **ditimpakan di atas proyek Tahap 3** (hanya file baru dan file yang berubah). Tidak ada paket Composer baru.

## 1. Struktur database

| Tabel | Kolom utama | Catatan |
|---|---|---|
| `purchases` | `number` (unik, `PUR-20261005-0001`), `supplier_id`, `supplier_invoice_number`, `purchase_date`, `due_date`, `subtotal`, `discount`, `tax`, `other_cost`, `grand_total`, `paid_amount`, `balance`, `payment_status` (LUNAS/HUTANG/SEBAGIAN), `status` (selesai/dibatalkan), `voided_at/by`, `void_reason` | Tidak ada edit dan tidak ada hapus. Semua uang `decimal(15,2)` |
| `purchase_items` | `purchase_id`, `product_id`, `product_name`, `sku`, `unit_name`, `quantity`, `unit_price`, `discount`, `line_total` | Nama, SKU, dan satuan disalin saat transaksi. Satu produk satu baris |
| `payments` | `number` (unik, `PAY-20261005-0001`), `payable_type/id`, `amount`, `method`, `paid_at`, `note`, `user_id`, `voided_at/by`, `void_reason` | Catatan setiap uang keluar. Tambah-saja, hanya boleh ditandai batal. Nanti dipakai juga untuk piutang |

## 2. Memasang

```bash
cp -R ../pos-toko-tahap4/. .
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder   # WAJIB: menambah izin baru void-purchase
php artisan test
php artisan stok:periksa
```

Tidak ada ekstensi PHP baru. Perhitungan uang memakai bilangan bulat (kelas `Uang`), bukan float dan bukan bcmath.

### File Tahap 3 yang ditimpa
`routes/web.php`, `app/Providers/AppServiceProvider.php` (morph map `purchase`), `app/Models/Supplier.php`,
`app/Services/SupplierService.php`, `app/Http/Controllers/SupplierController.php`,
`resources/views/suppliers/show.blade.php`, `database/seeders/RolePermissionSeeder.php`.

## 3. Cara kerja

**Mencatat pembelian** (`Pembelian → Pembelian baru`): pilih supplier, isi nomor faktur supplier dan tanggal, tambah produk
dengan mengetik nama/SKU atau scan barcode lalu Enter, isi jumlah, harga beli, dan diskon baris. Lalu diskon, pajak, biaya lain, dan pembayaran.
Layar menampilkan pratinjau total; **server selalu menghitung ulang**.

Saat disimpan, dalam **satu transaksi database**:
1. dokumen dan rincian pembelian dibuat dengan nomor otomatis,
2. stok tiap produk bertambah lewat `StockService` (ada mutasi `Pembelian` yang merujuk dokumen),
3. harga beli produk diperbarui (bila opsinya dicentang),
4. pembayaran awal dicatat bila ada.

Gagal di langkah mana pun, **semuanya dibatalkan**.

**Rumus:** subtotal baris = jumlah × harga − diskon baris. Grand total = jumlah semua baris − diskon + pajak + biaya lain.
Diskon, pajak, dan biaya lain berupa nominal Rupiah. Pembulatan setengah ke atas.

**Status pembayaran:** Dibayar 0 → HUTANG. Dibayar sebagian → SEBAGIAN. Dibayar penuh → LUNAS. Pembayaran melebihi total ditolak.
**Jatuh tempo wajib** diisi bila masih ada sisa hutang.

**Harga beli produk:** bila dicentang, harga beli di master produk menjadi harga bersih per unit pembelian ini
(setelah diskon baris). Itu menjadi dasar harga modal pada penjualan berikutnya. Histori pembelian lama tidak berubah karena tiap baris menyimpan harganya sendiri.

**Tidak ada edit.** Pembelian yang salah dibatalkan lalu dicatat ulang. Nomor faktur yang sama dari supplier yang sama
ditolak, kecuali pembelian sebelumnya sudah dibatalkan.

**Pembatalan (void):** butuh izin `void-purchase` dan alasan minimal 5 karakter. Stok dikembalikan (mutasi pembalik, tidak ada yang dihapus),
pembayaran ditandai batal, dan dicatat siapa, kapan, serta alasannya. **Ditolak bila sebagian barang sudah terjual** (stok tidak cukup untuk
dibalikkan). Pembatalan itu dibatalkan seluruhnya, dan pesannya mengarahkan ke Retur Pembelian. Harga beli produk tidak dikembalikan otomatis.

**Supplier:** halaman detail kini menampilkan jumlah pembelian, total pembelian, hutang, dan riwayat. Supplier yang masih punya hutang tidak bisa dihapus.

## 4. Hak akses

| Aksi | Super Admin | Admin | Kasir |
|---|:-:|:-:|:-:|
| Lihat daftar dan detail (`view-purchases`) | ✓ | ✓ | – |
| Catat pembelian (`create-purchase`) | ✓ | ✓ | – |
| Batalkan pembelian (`void-purchase`, izin baru) | ✓ | ✓ | – |

## 5. Keputusan desain

- **Tabel `payables` belum dibuat.** Hutang adalah pembelian berstatus selesai dengan `balance > 0`. Satu sumber kebenaran menghindari dua angka yang bisa tidak sinkron.
  Tahap 7 (modul Hutang) membangun tampilannya di atas `purchases` + `payments`, dan bila perlu menambah tabel untuk saldo awal.
- **Pembayaran lanjutan belum punya layar.** Layanannya sudah ada (`PaymentService::bayarPembelian`, dipakai juga untuk pembayaran awal), dan layarnya ada di Tahap 7.
- **Diskon baris dan diskon pembelian berupa nominal Rupiah**, bukan persen.
- **Jumlah dan harga dibatasi** (jumlah ≤ 99.999,999; harga ≤ Rp 99.999.999,99; total ≤ Rp 99.999.999.999,99) agar hitungan bilangan bulat selalu aman.
- **Pembatalan memakai jenis mutasi `Pembelian` dengan jumlah negatif** dan catatan "Pembatalan pembelian ...", sehingga daftar jenis mutasi tetap sesuai spesifikasi.

## 6. Cek manual

1. `php artisan db:seed --class=RolePermissionSeeder`, lalu login `admin@example.com` → menu Pembelian muncul.
2. Supplier → tambah satu supplier. Pembelian → Pembelian baru: cari "Ayam", tambah, jumlah 10, harga 8000, pilih "Bayar lunas", simpan.
3. Stok → Ayam Geprek bertambah 10; Riwayat mutasi menampilkan "Pembelian" yang merujuk nomor pembelian. Harga beli produk menjadi 8.000.
4. Buat pembelian lain dengan dibayar 0 → wajib jatuh tempo; statusnya HUTANG. Halaman Supplier menampilkan hutangnya.
5. Coba bayar melebihi total → ditolak.
6. Batalkan pembelian pertama dengan alasan → stok kembali, pembayaran ditandai batal, dokumen tetap ada.
7. Jual sebagian stok lewat Penyesuaian "Barang rusak", lalu coba batalkan pembelian → ditolak dan tidak ada yang berubah.
8. `php artisan stok:periksa` → semua konsisten.

Berikutnya: **Tahap 5 — Penjualan/POS** (layar kasir, keranjang, pembayaran, kembalian, nomor `POS-…`, stok keluar lewat `StockService`).
