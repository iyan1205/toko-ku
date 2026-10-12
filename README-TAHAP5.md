# Tahap 5 — Penjualan / Kasir (POS)

Paket ini **ditimpakan di atas proyek Tahap 4** (hanya file baru dan file yang berubah). Tidak ada paket Composer baru.

## 1. Struktur database

| Tabel | Kolom utama | Catatan |
|---|---|---|
| `sales` | `number` (unik, `POS-20261007-0001`), `customer_id`, `user_id` (kasir), `sold_at`, `subtotal`, `item_discount`, `discount`, `grand_total`, `paid_amount`, `initial_paid_amount`, `balance`, `tendered_amount`, `change_amount`, `payment_method`, `payment_status`, `status`, `voided_*` | Tidak ada edit dan tidak ada hapus. Semua uang `decimal(15,2)` |
| `sale_items` | `sale_id`, `product_id`, `product_name`, `sku`, `unit_name`, `quantity`, `unit_price`, **`cost_price`**, `discount`, `line_total` | `unit_price` dan `cost_price` adalah harga **saat transaksi**, disalin dari produk. Dasar laporan keuntungan historis (Tahap 23) |
| `held_sales` | `user_id`, `customer_id`, `label`, `cart` (JSON) | Transaksi yang ditahan (F9). Bukan transaksi keuangan, boleh dihapus. Maksimal 20 per kasir |
| `payments` | *(tabel Tahap 4, dipakai ulang)* | Pembayaran penjualan bernomor `RCV-…` (pembelian tetap `PAY-…`) |

`initial_paid_amount` menyimpan jumlah yang dibayar **saat transaksi dibuat**, terpisah dari `paid_amount` yang terus bertambah bila ada pelunasan piutang susulan (Tahap 7). Selisih keduanya dipakai untuk menolak pembatalan pada penjualan yang sudah dicicil setelah transaksinya selesai (lihat bagian 4).

## 2. Memasang

```bash
cp -R ../pos-toko-tahap5/. .
php artisan migrate
php artisan test
php artisan stok:periksa
```

Tidak ada migrasi atau seeder tambahan yang wajib dijalankan ulang (izin `create-sale`, `view-sales`, `view-own-sales`, `void-sale` sudah ada sejak Tahap 1).

### File Tahap 4 yang ditimpa
`routes/web.php`, `app/Providers/AppServiceProvider.php` (morph map `sale`), `app/Enums/PaymentMethod.php` (`untukPenjualan()`),
`app/Enums/PaymentStatus.php` (`labelPenjualan()`), `app/Services/PaymentService.php` (menambah `terimaPenjualan()`),
`app/Models/Customer.php` (relasi `sales()`), `app/Http/Controllers/CustomerController.php`, `resources/views/customers/show.blade.php`,
`resources/views/partials/badge-pembayaran.blade.php`, `resources/views/layouts/app.blade.php` (kelas `print:hidden` untuk cetak struk),
`app/Support/Uang.php` (pengaman overflow pada `kali()`, tambahan `kurangiPersen()`).

## 3. Cara kerja

**Layar Kasir** (`Kasir` di sidebar): tata letak dua kolom sesuai spesifikasi — kiri daftar/pencarian produk, kanan keranjang.
- Cari produk by nama/SKU atau scan barcode (AJAX, F2 fokus ke pencarian). Scan diikuti Enter langsung menambah ke keranjang bila hasilnya tepat satu.
- Keranjang: ubah jumlah (+/-), hapus baris, diskon transaksi (F8).
- Pelanggan: cari/pilih (AJAX); kosong berarti "Umum". Saldo piutang berjalan pelanggan ditampilkan bila ada.
- Metode pembayaran: Tunai, Transfer, QRIS, Debit, Kredit, E-Wallet, Piutang.
  - **Tunai**: masukkan uang diterima (F4), kembalian dihitung otomatis. Tombol "uang cepat" membulatkan ke atas.
  - **Non-tunai** (Transfer/QRIS/Debit/Kredit/E-Wallet): otomatis dianggap lunas penuh, tanpa kembalian.
  - **Piutang**: pelanggan wajib terdaftar (bukan "Umum"). Boleh isi bayar di muka (DP); sisanya menjadi piutang.
- **Hold (F9)**: menyimpan keranjang ke `held_sales` lalu mengosongkan layar. **Panggil kembali** dari daftar transaksi ditahan; harga diambil ulang dari produk saat dipanggil (bukan dari saat ditahan). Maksimal 20 transaksi tertahan per kasir.
- **Bayar (F10 saat di keranjang)**: mengirim keranjang ke server.
- Setelah tersimpan, kasir diarahkan ke halaman struk (`Penjualan/{nomor}`) dengan tombol **Cetak (F10)** — mencetak halaman apa adanya tanpa sidebar. Format thermal 58/80mm dan PDF resmi menyusul di Tahap 8.

**Yang dikerjakan satu transaksi saat penjualan disimpan** (`SaleService::buat`):
1. harga jual dan harga modal **selalu diambil ulang dari database**, bukan dari data yang dikirim browser — klien hanya mengirim `product_id`, jumlah, dan diskon;
2. diskon tetap produk (`Product::discount_percent`) diterapkan di server dengan hitungan bilangan bulat yang sama persis dengan pratinjau di layar kasir;
3. dokumen dan rincian penjualan dibuat dengan nomor `POS-…`;
4. stok tiap produk berkurang lewat `StockService` — bila kurang, ditolak dengan **"Stok tidak mencukupi."** dan seluruh transaksi dibatalkan;
5. untuk metode Piutang, saldo piutang pelanggan bertambah sebesar grand total;
6. pembayaran (tunai/non-tunai penuh, atau DP untuk piutang) dicatat bila ada.

**Tidak ada edit.** Penjualan yang salah dibatalkan (void) lalu dicatat ulang. Batal butuh izin `void-sale` dan alasan minimal 5 karakter:
stok dikembalikan, pembayaran ditandai batal, dan piutang pelanggan dikoreksi. **Ditolak bila sudah ada pelunasan piutang susulan**
(dibayar lebih besar dari saat transaksi dibuat) — gunakan Retur Penjualan (Tahap 6). DP yang diambil **bersamaan** saat transaksi
dibuat tidak menghalangi pembatalan, karena itu koreksi langsung, bukan interaksi terpisah di kemudian hari.

## 4. Hak akses

| Aksi | Super Admin | Admin | Kasir |
|---|:-:|:-:|:-:|
| Buka Kasir, catat penjualan (`create-sale`) | ✓ | – | ✓ |
| Lihat semua penjualan (`view-sales`), lihat kolom laba | ✓ | ✓ | – |
| Lihat penjualan **miliknya sendiri** (`view-own-sales`) | — | — | ✓ |
| Batalkan penjualan (`void-sale`) | ✓ | ✓ | – |

Sesuai spesifikasi, Admin pada matriks bawaan memang **tidak** memegang `create-sale` (tugasnya mengawasi, bukan melayani kasir);
ubah di `RolePermissionSeeder` bila toko Anda ingin Admin ikut melayani. Kasir yang membuka penjualan milik kasir lain mendapat 403.

## 5. Keputusan desain

- **Harga tidak pernah dipercaya dari klien.** Request penjualan bahkan tidak punya field harga — hanya `product_id`, `quantity`, `discount`.
  Ini satu tingkat lebih ketat dari Pembelian (yang memang mengizinkan Admin memasukkan harga beli).
- **Diskon tetap produk dihitung dengan bilangan bulat** (`Uang::kurangiPersen()`, baru di tahap ini) supaya hasil akhir di server selalu
  identik dengan pratinjau yang dilihat kasir, bukan sekadar mendekati.
- **Retur Penjualan belum ada** (Tahap 6). Pembatalan sengaja dibuat ketat (menolak bila sudah dicicil) justru supaya kasus "barang sudah
  sebagian dibayar/diambil" diarahkan ke Retur, bukan dipaksakan lewat pembatalan.
- **Thermal/PDF resmi belum ada** (Tahap 8). Struk saat ini adalah halaman HTML biasa yang dicetak lewat `window.print()` dengan sidebar
  disembunyikan (`print:hidden`), cukup untuk uji coba alur kasir.
- **`held_sales` menyimpan ID produk + jumlah + diskon saja**, bukan harga. Memanggil kembali transaksi yang ditahan selalu mengambil
  harga terkini, supaya perubahan harga di tengah hari tidak membuat transaksi lama memakai harga basi.
- **Dua kasir yang menyimpan di detik yang sama** bisa menghitung nomor urut yang sama; `SaleService` menangkap `UniqueConstraintViolationException`
  dan mengulang hingga 3 kali dengan nomor yang dihitung ulang.

## 6. Cek manual

1. `php artisan db:seed --class=MasterDataSeeder` bila stok produk contoh masih 0, lalu isi lewat Penyesuaian Stok (Tahap 3).
2. Login `kasir@example.com` → menu Kasir terbuka. Cari "Ayam", klik untuk menambah, ubah jumlah jadi 2.
3. Tekan F8, isi diskon 1000. Metode Tunai, tekan F4, isi bayar lebih besar dari total → kembalian terhitung otomatis.
4. Tekan F10 (atau klik Bayar) → diarahkan ke halaman struk. Stok produk berkurang; cek di menu Stok.
5. Kembali ke Kasir, tambah produk, tekan F9 → keranjang kosong, badge "Ditahan" bertambah. Klik "Ditahan (F9)" → panggil kembali transaksi tadi → keranjang terisi lagi.
6. Buat transaksi dengan metode Piutang ke pelanggan terdaftar (bukan Umum) tanpa DP → cek saldo piutang pelanggan bertambah di halaman Pelanggan.
7. Login `admin@example.com` → Penjualan menampilkan semua transaksi dan kolom laba; buka salah satu, klik "Batalkan penjualan ini" dengan alasan → stok kembali, status "Dibatalkan".
8. Login `kasir@example.com` lagi → coba buka penjualan milik kasir lain lewat tautan langsung → 403.
9. `php artisan stok:periksa` → semua konsisten.

Berikutnya: **Tahap 6 — Retur** (Retur Penjualan dan Retur Pembelian, stok dan uang disesuaikan lewat service yang sama).
