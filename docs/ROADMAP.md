# Roadmap Toko-ku

Status per 10 Nov 2026, setelah Fase 8 (fondasi enterprise). Rincian keputusan dan rencana tiap fase ada di [AUDIT.md](AUDIT.md); spesifikasi di [REDESIGN-TOKOKU.md](REDESIGN-TOKOKU.md), rencana Fase 8–19 di `ROADMAP-ENTERPRISE-TOKOKU.md`. Cara deploy, queue worker, dan scheduler: [DEPLOY.md](DEPLOY.md).

## Ringkasan

| Modul | Status | Catatan singkat |
|---|---|---|
| Dashboard | Jadi | KPI, grafik 7/30 hari, terlaris, stok menipis, hutang jatuh tempo, sesuai hak akses |
| Kasir (POS) | Jadi | Layar kasir baru, split bayar, pajak, sesi kasir, retur, piutang |
| Inventori | Jadi | Multi-gudang, transfer, opname draft → selesai, kartu stok |
| Pembelian | Jadi | PO → penerimaan parsial → faktur & hutang, retur, pembayaran hutang |
| Akuntansi | Kerangka | Bagan akun, jurnal umum (draf, persetujuan, posting), jurnal otomatis, kunci periode. Buku besar & laporan keuangan belum |
| SDM | Sebagian | Karyawan, departemen & jabatan, absensi, jadwal, shift, cuti. Penggajian belum |
| Laporan | Sebagian | Laporan penjualan ada. Pembelian, stok, laba kotor, kinerja kasir belum |
| Pengguna & Hak Akses | Jadi | Peran dinamis, matriks izin, akses cabang per pengguna, log aktivitas, profil |
| Pengaturan | Jadi | Profil toko, cabang, pajak, metode bayar, struk, penomoran, kasir, akuntansi, alur persetujuan, sistem, backup |
| Fondasi lintas modul | Jadi | Data per cabang, persetujuan berjenjang, notifikasi, lampiran, status dokumen, kunci periode, impor/ekspor massal, queue & scheduler |

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

### Fondasi enterprise (Fase 8)
- **Data per cabang**: tabel `companies` (perusahaan utama) dan `company_id`/`branch_id` di 12 tabel transaksi. Data lama diisi dari cabang gudangnya atau dokumen asalnya. Trait `BelongsToBranch` + `BranchScope` menyaring data ke cabang aktif di topbar; pilihan "Semua cabang"; akses cabang per pengguna (Pengguna → ubah → Akses cabang). Selama toko punya satu cabang, data tidak disaring.
- **Persetujuan berjenjang**: Pengaturan → Alur Persetujuan (jenis dokumen, kondisi nilai seperti "total lebih dari Rp 5.000.000", cabang, prioritas, tahap per peran atau pengguna). Halaman **Persetujuan Saya** (menunggu saya, pengajuan saya, keputusan saya) dan panel persetujuan di halaman dokumen. Dipakai purchase order dan jurnal manual. Pengaju tidak bisa menyetujui sendiri kecuali diizinkan alurnya. Tahap disalin saat diajukan, jadi mengubah alur tidak mengganggu pengajuan yang berjalan.
- **Pusat notifikasi**: lonceng di topbar (notifikasi + stok menipis), tandai dibaca, halaman Notifikasi, preferensi kanal per pengguna di Profil Saya. Lonceng langsung terisi; email lewat queue.
- **Lampiran dokumen**: unggah seret-lepas, pratinjau gambar/PDF, unduh, hapus. Dipasang di purchase order, faktur pembelian, penerimaan barang, dan jurnal. Izin mengikuti dokumennya.
- **Status dokumen**: trait `HasDocumentStatus` (draf → diajukan → disetujui → diposting, atau dibatalkan) dengan nomor dari Penomoran Dokumen. Dipakai jurnal manual; saldo akun hanya menghitung jurnal yang diposting.
- **Kunci periode**: Akuntansi → Kunci Periode (tutup/buka kembali per bulan, dengan alasan dan log). Transaksi bertanggal di bulan tertutup ditolak di formulir dan di service (penjualan, pembelian, penerimaan, pembayaran, retur, opname, transfer, penyesuaian stok, jurnal).
- **Impor massal**: templat Excel dengan lembar petunjuk, unggah → pratinjau per baris → proses baris yang benar, unduh baris yang salah. Dipakai Pelanggan dan Supplier.
- **Ekspor besar lewat queue**: ekspor > 3.000 baris dibuat di latar belakang bila queue worker berjalan, lalu muncul di **Ekspor Saya** dengan notifikasi.
- **Queue & scheduler**: tanda hidup worker dan scheduler, halaman Pengaturan → Sistem (status, antrean, job gagal), tugas pembersihan terjadwal. Cara memasangnya di [DEPLOY.md](DEPLOY.md).

## Belum dibangun

### Akuntansi
- **Buku Besar**: mutasi dan saldo berjalan per akun per periode. Data sudah tersedia di `journal_lines`; tinggal halaman dan ekspor.
- **Kas & Bank**: pemasukan/pengeluaran non-penjualan (misal bayar listrik) tanpa menulis jurnal manual, rekonsiliasi bank.
- **Laporan Keuangan**: Laba Rugi, Neraca, Arus Kas.
- **Tutup Buku**: checklist tutup bulan, jurnal penutup tahun, dan buka kembali hanya oleh peran tertentu. Kunci periode bulanan sudah ada (Fase 8).
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

### Fondasi (lanjutan Fase 8)
- Server: queue worker (systemd) dan cron scheduler belum dipasang; email masih `MAIL_MAILER=log`; batas unggah PHP 2 MB. Langkahnya di [DEPLOY.md](DEPLOY.md).
- Impor produk masih memakai halaman lamanya (semua-atau-tidak-sama-sekali); belum dipindah ke kerangka impor baru.
- Persetujuan baru dipakai purchase order dan jurnal manual. Retur penjualan/pembelian, stok opname, dan cuti masih memakai persetujuan lamanya (cuti punya alur bertahap sendiri).
- Transfer stok antar cabang tercatat di cabang gudang asal; status kirim/terima dan barang dalam perjalanan direncanakan di Fase 16.
- Saldo di Bagan Akun dan saringan cabang: laporan keuangan per cabang menyusul di Fase 9/11.
- Multi-perusahaan: tabel `companies` sudah ada, tetapi hanya satu perusahaan yang dipakai (Fase 17).

### Lain-lain
- Uji di tablet kasir, scanner barcode fisik, dan printer struk di toko.
- Format tanggal di seluruh aplikasi masih `dd-mm-yyyy`, padahal aturan proyek meminta "08 Nov 2026" (`App\Support\Format::tanggal`, dipakai juga di struk). Perlu keputusan pemilik.

## Catatan teknis untuk pengembang
- Semua perubahan stok lewat `StockService::catat()` dengan gudang; cek konsistensi dengan `php artisan stok:periksa`.
- Semua jurnal lewat `App\Services\Accounting\JournalService`; jurnal otomatis lewat `AutoJournalService` (idempoten per dokumen + kejadian).
- Event antar modul memakai `ShouldDispatchAfterCommit`. Pembayaran yang dibuat bersamaan dengan transaksinya dikirim dengan `atCheckout = true` dan sudah termasuk jurnal transaksinya.
- Nama index/constraint MySQL maksimal 64 karakter (dicek `SkemaDatabaseTest`, karena test memakai SQLite).
- Model transaksi baru memakai `BelongsToBranch`. Query internal yang harus melihat semua cabang (cek nomor dokumen, jurnal otomatis, sesi kasir) memakai `withoutGlobalScope(BranchScope::class)`; query builder dengan `join` menyaring cabang sendiri lewat `CurrentBranch::scopeIds()`.
- Dokumen yang butuh persetujuan: trait `HasApproval` + `config/approval.php`. Lampiran: `HasAttachments` + `config/attachments.php`. Impor: turunan `App\Imports\ImportDefinition` + `config/imports.php`. Notifikasi: turunan `App\Notifications\AppNotification` + `config/notification-types.php`.
