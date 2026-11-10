# Roadmap Toko-ku

Status per 10 Nov 2026, setelah Fase 7 redesign. Rincian keputusan dan rencana tiap fase ada di [AUDIT.md](AUDIT.md); spesifikasi di [REDESIGN-TOKOKU.md](REDESIGN-TOKOKU.md).

## Ringkasan

| Modul | Status | Catatan singkat |
|---|---|---|
| Dashboard | Jadi | KPI, grafik 7/30 hari, terlaris, stok menipis, hutang jatuh tempo, sesuai hak akses |
| Kasir (POS) | Jadi | Layar kasir baru, split bayar, pajak, sesi kasir, retur, piutang |
| Inventori | Jadi | Multi-gudang, transfer, opname draft → selesai, kartu stok |
| Pembelian | Jadi | PO → penerimaan parsial → faktur & hutang, retur, pembayaran hutang |
| Akuntansi | Kerangka | Bagan akun, jurnal umum, jurnal otomatis. Buku besar & laporan keuangan belum |
| SDM | Sebagian | Karyawan, departemen & jabatan, absensi, jadwal, shift, cuti. Penggajian belum |
| Laporan | Sebagian | Laporan penjualan ada. Pembelian, stok, laba kotor, kinerja kasir belum |
| Pengguna & Hak Akses | Jadi | Peran dinamis, matriks izin, log aktivitas, profil |
| Pengaturan | Jadi | Profil toko, cabang, pajak, metode bayar, struk, penomoran, kasir, akuntansi, backup |

Menu yang belum jadi tetap tampil dengan label **Segera Hadir** (diatur di `config/menu.php`).

## Sudah dibangun

### Fondasi (Fase 1–3)
- Komponen UI `x-ui.*`, layout aplikasi dan layout kasir, menu dinamis dari `config/menu.php`.
- Izin `{modul}.{fitur}.{aksi}` (katalog di `config/permission-matrix.php`), 8 peran bawaan, matriks hak akses, log aktivitas, halaman Profil Saya.
- Setiap route dijaga middleware `permission:`; `ProteksiRouteTest` gagal bila ada route baru yang lupa dijaga.

### Pengaturan & Dashboard (Fase 4)
- Helper `setting()`, Profil Toko (logo, NPWP), Cabang, Pajak, Metode Pembayaran, Struk & Printer, Penomoran Dokumen, Backup (`mysqldump`, terjadwal).
- Dashboard berisi KPI dan grafik sesuai hak akses.

### Inventori & Pembelian (Fase 5)
- Gudang, stok per gudang lewat satu `StockService`, transfer, opname per gudang, `stok:periksa`.
- PO, penerimaan barang parsial, faktur, retur pembelian, hutang supplier.
- Semua tabel daftar: cari, filter, urut, halaman, ekspor Excel/PDF (`ListExport`).

### Kasir (Fase 6)
- Layar kasir dengan shortcut keyboard, scan barcode, grid kategori.
- Pembayaran multi-metode, pajak termasuk/belum termasuk harga, transaksi ditahan.
- Sesi kasir (modal awal, tutup dengan hitung uang, verifikasi), retur penjualan (diajukan → disetujui), piutang pelanggan.

### Akuntansi (Fase 7, kerangka)
- **Bagan akun (COA)** bertingkat: 43 akun standar toko retail Indonesia, dipasang dari halaman Bagan Akun ("Pasang COA standar") atau `php artisan db:seed --class=ChartOfAccountsSeeder`. Akun sistem (dipakai jurnal otomatis) tidak bisa dihapus/dinonaktifkan.
- **Jurnal umum**: daftar, jurnal manual (debit = kredit diperiksa dengan bilangan bulat), jurnal pembalik untuk koreksi. Jurnal tidak pernah diubah atau dihapus.
- **Jurnal otomatis** (`App\Listeners\CreateAutoJournal`), bisa dimatikan di Pengaturan → Akuntansi:

  | Kejadian | Debit | Kredit |
  |---|---|---|
  | Penjualan | Kas/Bank (dibayar saat transaksi), Piutang (sisa), HPP | Penjualan, PPN Keluaran, Persediaan |
  | Barang diterima / pembelian langsung | Persediaan, PPN Masukan | Kas/Bank (dibayar saat transaksi), Hutang (sisa) |
  | Pelunasan piutang | Kas/Bank | Piutang |
  | Pembayaran hutang | Hutang | Kas/Bank |
  | Retur penjualan | Retur Penjualan, PPN Keluaran, Persediaan | Piutang, Kas/Bank (uang kembali), HPP |
  | Retur pembelian | Hutang, Piutang Lain-lain | Persediaan |
  | Pembatalan penjualan/pembelian | jurnal pembalik dokumen dan pembayarannya | |

  Kode akun yang dipakai ada di `config/accounting.php`. Kegagalan membuat jurnal tidak mengganggu transaksi; kegagalannya tercatat di Log Aktivitas (kelompok "akuntansi").

### SDM (Fase 7, kerangka)
- **Karyawan**: daftar, tambah/ubah/hapus, ekspor. Kode otomatis `KRY-0001`. Tautan opsional ke akun login (satu akun untuk satu karyawan); karyawan tanpa akun tetap bisa dicatat.
- **Departemen & Jabatan**: daftar, tambah/ubah/hapus, ekspor. Tidak bisa dihapus bila masih dipakai karyawan.
- Absensi, Jadwal, Shift, dan Cuti (sudah ada sebelum redesign) tetap memakai akun login (`user_id`).

## Belum dibangun

### Akuntansi
- **Buku Besar**: mutasi dan saldo berjalan per akun per periode. Data sudah tersedia di `journal_lines`; tinggal halaman dan ekspor.
- **Kas & Bank**: pemasukan/pengeluaran non-penjualan (misal bayar listrik) tanpa menulis jurnal manual, rekonsiliasi bank.
- **Laporan Keuangan**: Laba Rugi, Neraca, Arus Kas.
- **Tutup Buku**: mengunci periode agar jurnal lama tidak bisa ditambah atau dibalik.
- **Saldo awal**: input saldo awal akun (modal, kas, persediaan) saat mulai memakai akuntansi.
- **Jurnal untuk mutasi stok non-transaksi**: penyesuaian stok, barang rusak/hilang, dan selisih opname belum dijurnal (akun `5-2000 Selisih Persediaan` sudah disiapkan). Transfer antar gudang tidak perlu dijurnal.
- **Jurnal untuk transaksi lama**: transaksi sebelum Fase 7 terpasang tidak dijurnal otomatis. Bila perlu, catat lewat saldo awal atau perintah backfill.
- **Pengaturan akun dari aplikasi**: kode akun jurnal otomatis masih diatur di `config/accounting.php`.
- Kartu kredit, QRIS, dan e-wallet masih masuk ke akun Bank yang sama; biaya MDR belum dicatat otomatis.

### SDM
- **Penggajian**: komponen gaji, potongan, hitung dari absensi, slip gaji, dan jurnal beban gaji (rencana event `PayrollPosted` → D Beban Gaji / K Kas atau Hutang Gaji).
- Absensi, Jadwal, dan Cuti belum bisa untuk karyawan tanpa akun login, karena masih berbasis `user_id`. Rencana: pindah ke `employee_id` dengan migrasi data dari `employees.user_id`.
- Halaman detail karyawan (riwayat absensi, cuti, dokumen) belum ada; data karyawan dibuka lewat halaman ubah.

### Laporan
- Laporan Pembelian (per supplier, produk, periode), Laporan Stok (per gudang, nilai persediaan), Laba Kotor, Kinerja Kasir.

### Lain-lain
- Uji di tablet kasir, scanner barcode fisik, dan printer struk di toko.
- Format tanggal di seluruh aplikasi masih `dd-mm-yyyy`, padahal aturan proyek meminta "08 Nov 2026" (`App\Support\Format::tanggal`, dipakai juga di struk). Perlu keputusan pemilik.
- Data multi-cabang: cabang sudah ada, tetapi laporan dan dashboard belum dipisah per cabang.

## Catatan teknis untuk pengembang
- Semua perubahan stok lewat `StockService::catat()` dengan gudang; cek konsistensi dengan `php artisan stok:periksa`.
- Semua jurnal lewat `App\Services\Accounting\JournalService`; jurnal otomatis lewat `AutoJournalService` (idempoten per dokumen + kejadian).
- Event antar modul memakai `ShouldDispatchAfterCommit`. Pembayaran yang dibuat bersamaan dengan transaksinya dikirim dengan `atCheckout = true` dan sudah termasuk jurnal transaksinya.
- Nama index/constraint MySQL maksimal 64 karakter (dicek `SkemaDatabaseTest`, karena test memakai SQLite).
