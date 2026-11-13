# Roadmap Toko-ku

Status per 16 Nov 2026, setelah Fase 16 poin 1–8 (inventori lanjutan; laporan inventori poin 9 belum). Rincian keputusan dan rencana tiap fase ada di [AUDIT.md](AUDIT.md); spesifikasi di [REDESIGN-TOKOKU.md](REDESIGN-TOKOKU.md), rencana Fase 8–19 di `ROADMAP-ENTERPRISE-TOKOKU.md`. Cara deploy, queue worker, dan scheduler: [DEPLOY.md](DEPLOY.md).

## Ringkasan

| Modul | Status | Catatan singkat |
|---|---|---|
| Dashboard | Jadi | KPI, grafik 7/30 hari, terlaris, stok menipis, hutang jatuh tempo, sesuai hak akses |
| Kasir (POS) | Jadi | Layar kasir baru, split bayar, pajak, sesi kasir, retur, piutang, faktur penjualan manual, pelunasan gabungan, umur & kartu piutang |
| Inventori | Jadi | Multi-gudang, transfer, opname draft → selesai, kartu stok |
| Pembelian | Jadi | PO → penerimaan parsial → faktur & hutang, retur, tagihan manual, pembayaran gabungan, jadwal, umur & kartu hutang |
| Akuntansi | Jadi | Bagan akun, saldo awal, jurnal umum & berulang, jurnal otomatis, buku besar, neraca saldo, cost center, kas & bank, rekonsiliasi, giro, aset tetap & penyusutan, anggaran, PPN & PPh 23/4(2), laporan keuangan, tutup buku |
| SDM | Sebagian | Data karyawan lengkap, kontrak & mutasi, bagan organisasi, dokumen, offboarding, absensi, jadwal, shift, cuti, Layanan Mandiri (lembur, reimbursement). Penggajian belum |
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

### Inventori & Pembelian (Fase 5 dan Fase 16)
- Gudang, stok per gudang lewat satu `StockService`, transfer, opname per gudang, `stok:periksa`.
- PO, penerimaan barang parsial, faktur, retur pembelian, hutang supplier.
- Semua tabel daftar: cari, filter, urut, halaman, ekspor Excel/PDF (`ListExport`).
- **Biaya persediaan** (Fase 16): metode HPP rata-rata tertimbang atau FIFO (Pengaturan → Inventori). Setiap stok masuk menjadi lot dengan biaya per unit; HPP penjualan, jurnal HPP, retur, opname, penyesuaian, dan transfer memakai biaya mutasi. Pembatalan/retur mengembalikan lot dan biaya aslinya. Nilai persediaan di halaman Stok dari nilai gudang yang sebenarnya.
- **Batch & kedaluwarsa** (produk "lacak batch"): nomor batch wajib saat barang diterima, stok keluar kedaluwarsa terdekat dulu (FEFO), batch kedaluwarsa tidak dijual, halaman Mendekati Kedaluwarsa dan pengingat harian `stok:kedaluwarsa` (07:10). **Nomor seri** opsional per produk.
- **Varian** (ukuran × warna, dst.): tiap kombinasi produk sendiri dengan SKU/barcode/stok/harga; induk tidak dijual. **Paket**: menjual paket mengurangi stok komponen (resep/BOM).
- **Multi-satuan**: satuan lain dengan isi, barcode, dan harga jual (misal DUS isi 40). Kasir: scan barcode dus, ganti satuan per baris; PO/pembelian bisa diisi per dus; stok tetap dalam satuan dasar.
- **Transfer antar cabang** dua langkah: dikirim (dalam perjalanan) lalu diterima gudang tujuan; kekurangan menjadi selisih persediaan. Dalam satu cabang tetap langsung selesai.
- **Stok min/maks per gudang** dan **Saran Pesan Ulang** yang langsung menjadi draft PO per supplier.
- **Stok opname dari HP**: scan barcode (kamera/scanner), opsi bekukan stok gudang selama dihitung, penyesuaian lewat alur persetujuan "Stok opname".
- **Cetak label** barcode Code 128 & harga (printer label atau stiker A4), **impor produk** lewat kerangka impor dengan satuan besar dan batch/seri.
- Belum: laporan nilai persediaan per tanggal, perputaran stok, slow/dead moving, kartu stok per batch (Fase 16 poin 9).

### Kasir (Fase 6)
- Layar kasir dengan shortcut keyboard, scan barcode, grid kategori.
- Pembayaran multi-metode, pajak termasuk/belum termasuk harga, transaksi ditahan.
- Sesi kasir (modal awal, tutup dengan hitung uang, verifikasi), retur penjualan (diajukan → disetujui), piutang pelanggan.

### Akuntansi (Fase 7 dan Fase 9)
- **Bagan akun (COA)** bertingkat sebagai pohon (akun induk bisa diciutkan, saldo induk = jumlah akun di bawahnya), dengan kelompok, saldo normal, tanda **akun kas/bank**, dan status aktif. 46 akun standar toko retail Indonesia dipasang dari halaman Bagan Akun atau `php artisan db:seed --class=ChartOfAccountsSeeder`. COA lama mendapat tawaran "Tambahkan akun standar" untuk akun baru (Rekening Antar Cabang, Ekuitas Saldo Awal, Diskon Penjualan). **Impor Excel** lewat kerangka impor (kode yang ada diperbarui, akun induk boleh ditulis di baris sebelumnya).
- **Saldo awal** (Akuntansi → Saldo Awal): saldo akun, nilai persediaan per gudang (bisa diisi dari stok saat ini), piutang per pelanggan, dan hutang per supplier, per cabang. Draf → posting menjadi satu jurnal; selisih masuk `3-9000 Ekuitas Saldo Awal`. Saldo awal posted dibatalkan dengan jurnal pembalik di tanggal yang sama. Nilai persediaan tidak mengubah jumlah stok.
- **Jurnal umum**: multi-baris, wajib seimbang (bilangan bulat sen), **cost center per baris** (opsional), lampiran, persetujuan, draf → posting, jurnal pembalik untuk koreksi, dan **balik otomatis** pada tanggal tertentu (untuk akrual). Jurnal posted tidak pernah diubah atau dihapus.
- **Jurnal berulang** (Akuntansi → Jurnal Berulang): templat setiap 1/2/3/6/12 bulan pada tanggal tertentu (tanggal 31 = akhir bulan), langsung posting atau draf. Dibuat scheduler `akuntansi:jurnal-terjadwal` setiap 00:20; jadwal yang terlewat ikut dibuat, satu periode tidak pernah dobel. Tombol "Buat jurnal sekarang" untuk server tanpa scheduler.
- **Pemetaan akun** (Pengaturan → Akuntansi): akun untuk penjualan, diskon, retur, PPN keluaran/masukan, piutang, HPP, persediaan, hutang, selisih persediaan, rekening antar cabang, gaji, ekuitas saldo awal, laba ditahan, dan **akun per metode pembayaran** (misal QRIS ke rekening BCA). Bawaan dari kode di `config/accounting.php`; dibaca lewat `AccountMappingService`. Akun yang dipetakan otomatis menjadi akun sistem.
- **Jurnal otomatis** (`App\Listeners\CreateAutoJournal`), bisa dimatikan di Pengaturan → Akuntansi:

  | Kejadian | Debit | Kredit |
  |---|---|---|
  | Penjualan | Kas/Bank (dibayar saat transaksi), Piutang (sisa), Diskon Penjualan*, HPP | Penjualan, PPN Keluaran, Persediaan |
  | Barang diterima / pembelian langsung | Persediaan, PPN Masukan | Kas/Bank (dibayar saat transaksi), Hutang (sisa) |
  | Pelunasan piutang | Kas/Bank | Piutang |
  | Pembayaran hutang | Hutang | Kas/Bank |
  | Retur penjualan | Retur Penjualan, PPN Keluaran, Persediaan | Piutang, Kas/Bank (uang kembali), HPP |
  | Retur pembelian | Hutang, Piutang Lain-lain | Persediaan |
  | Stok opname (selisih lebih / kurang) | Persediaan / Selisih Persediaan | Selisih Persediaan / Persediaan |
  | Penyesuaian stok, barang rusak/hilang | sama dengan stok opname, per mutasi | |
  | Transfer stok antar cabang | cabang asal: Rekening Antar Cabang; cabang tujuan: Persediaan | cabang asal: Persediaan; cabang tujuan: Rekening Antar Cabang |
  | Pembatalan penjualan/pembelian/transfer | jurnal pembalik dokumen dan pembayarannya | |

  \* Diskon dipisah bila peran "Diskon penjualan" dipetakan (bawaan `4-1200`); bila dikosongkan, penjualan dicatat bersih. Nilai barang untuk opname, penyesuaian, dan transfer = jumlah x harga pokok produk saat itu. Transfer di dalam satu cabang tidak dijurnal. Kegagalan membuat jurnal tidak mengganggu transaksi; kegagalannya tercatat di Log Aktivitas (kelompok "akuntansi").
- **Tautan dua arah**: halaman jurnal membuka dokumen sumbernya (penyesuaian stok membuka kartu stok), dan halaman penjualan, faktur pembelian, penerimaan barang, retur, stok opname, transfer, dan saldo awal menampilkan jurnalnya.
- **Buku besar** per akun: saldo awal, mutasi, dan saldo berjalan; saringan periode, cabang, dan cost center; ekspor Excel/PDF. **Neraca saldo**: saldo awal, mutasi debit/kredit, dan saldo akhir per akun, dikelompokkan, dengan tanda seimbang; klik akun untuk membuka buku besarnya. Keduanya hanya menghitung jurnal posted.
- **Cost center / departemen** (Akuntansi → Cost Center): dimensi laporan di baris jurnal, bisa dihubungkan ke departemen SDM. Yang sudah dipakai jurnal tidak bisa dihapus, cukup dinonaktifkan.
- **Kunci periode** (Fase 8) tetap berlaku untuk semua jurnal di atas.

### Kas & Bank, Piutang, Hutang (Fase 10)
- **Rekening** (Kas & Bank → Rekening): kas, rekening bank/e-wallet, dan kas kecil dengan plafon. Setiap rekening menempel pada satu akun kas/bank di Bagan Akun (Kas 1-1100 dan Bank 1-1200 langsung dibuatkan; rekening baru boleh membuat akunnya otomatis). Saldo = saldo akun dari jurnal posted. Kas kecil menampilkan kekurangan terhadap plafon dan tombol **Isi ulang**.
- **Transaksi kas**: penerimaan (D rekening | K akun per baris), pengeluaran (D akun per baris | K rekening), dan transfer antar rekening (+ biaya admin). Draf → posting; pengeluaran dan transfer mengikuti alur persetujuan `cash_out`/`cash_transfer` bila ada yang cocok. Transaksi posted dibatalkan dengan jurnal pembalik. Nomor BKM/BKK/TRB dari Penomoran Dokumen.
- **Rekonsiliasi bank**: impor rekening koran CSV/Excel (templat tersedia; kolom Masuk/Keluar atau Jumlah bertanda), cocok otomatis bila nominal sama dan tanggal ±3 hari, cocok manual, **catat** mutasi yang belum ada di buku (misal biaya admin) sebagai transaksi kas, ringkasan selisih, laporan PDF, dan kunci.
- **Faktur penjualan manual** (Kasir → Faktur Penjualan) dan **tagihan pembelian manual** (Pembelian → Tagihan Pembelian) untuk jasa, sewa, atau biaya non-stok: baris akun x jumlah x harga, pajak opsional, jatuh tempo dari termin pelanggan/supplier (kolom baru di formulirnya, bawaan 30 hari). Penjualan piutang di kasir juga kini punya jatuh tempo.
- **Pelunasan gabungan** (Pelunasan Piutang / Pembayaran Hutang): satu uang untuk banyak dokumen (penjualan kasir, faktur pembelian, faktur manual, saldo awal), sebagian atau penuh, tombol "Bagi otomatis". Kelebihan uang menjadi **uang muka** (2-1500 / 1-1360) dan bisa dipakai di pelunasan berikutnya. **Nota kredit/debit** mengurangi tagihan tanpa uang. Pembatalan mengembalikan sisa dokumen dan membalik jurnal. Pelunasan satu dokumen dari halaman lama tetap ada.
- **Giro/cek mundur** (Kas & Bank → Giro & Cek): pelunasan dengan cara bayar giro mengurangi faktur dan mencatat Cek & Giro Diterima / Hutang Giro; **cair** memindahkan uangnya ke rekening, **ditolak** membatalkan pelunasannya.
- **Laporan**: umur piutang/hutang per pihak (dari tanggal faktur atau dari jatuh tempo), kartu piutang/hutang dengan saldo berjalan dan uang muka, jadwal pembayaran hutang. **Pengingat jatuh tempo** harian (07:00, lonceng + email) ke pengguna yang boleh melihat piutang/hutang.
- **Saldo awal**: piutang/hutang per pihak dari saldo awal kini menjadi faktur saldo awal yang bisa dilunasi dan masuk umur/kartu. Saldo awal yang sudah diposting sebelum Fase 10 dibuatkan lewat `php artisan keuangan:faktur-saldo-awal` (sekali, aman diulang).
- Semua dijurnal otomatis lewat pemetaan akun (peran baru: uang muka pelanggan/pembelian, nota kredit/debit, giro diterima/dikeluarkan, biaya administrasi bank).

### Aset, anggaran, pajak, laporan keuangan, tutup buku (Fase 11)
- **Kategori aset** (Akuntansi → Aset Tetap → Kategori): umur manfaat (bulan), metode garis lurus atau saldo menurun (tarif % per tahun), akun aset/akumulasi/beban. Tombol "Pasang kategori standar" (kelompok 1, kelompok 2, bangunan).
- **Aset tetap**: kode otomatis, kategori, tanggal & harga perolehan, nilai sisa, lokasi, penanggung jawab (karyawan atau nama), cost center, nomor seri, lampiran. Saat disimpan dijurnal D Aset | K akun pembayaran; aset lama bisa dicatat dengan akumulasi penyusutan awal. **Kartu aset** menampilkan riwayat dan sisa jadwal penyusutan.
- **Penyusutan bulanan**: scheduler `aset:penyusutan` tanggal 1 pukul 00:40 menyusutkan bulan lalu (satu jurnal per cabang: D Beban Penyusutan | K Akumulasi, per cost center). Bulan yang terlewat ikut dikejar. Bisa juga dijalankan manual dari halaman Penyusutan dan dibatalkan selama belum ada penyusutan sesudahnya.
- **Pelepasan aset**: dijual (uang ke rekening kas/bank) atau dihapus; laba/rugi pelepasan otomatis ke 4-2100 / 6-1950. Syaratnya penyusutan sudah dihitung sampai bulan sebelum pelepasan.
- **Anggaran** (Akuntansi → Anggaran): nilai per akun pendapatan/HPP/beban per bulan, untuk semua cabang atau satu cabang/cost center; salin ke tahun berikutnya. Laporan anggaran vs realisasi dengan persentase dan peringatan (beban melebihi anggaran, pendapatan di bawah target).
- **Pajak**: tarif PPh 23 dan PPh 4(2) di Pengaturan → Tarif Pajak dengan tanggal berlaku (bawaan PPh 23 jasa/sewa 2%, PPh 4(2) sewa bangunan 10%, konstruksi 1,75%). Tagihan pembelian manual bisa memotong PPh: hutang ke supplier berkurang dan potongannya masuk Hutang PPh 23 / 4(2). Akuntansi → Pajak: **Rekap PPN** per masa (keluaran, masukan, kurang/lebih bayar), **Faktur PPN** keluaran/masukan dengan ekspor Excel/CSV yang kolomnya diatur di `config/tax-export.php`, dan **Bukti potong PPh** per masa dengan isian nomor dan tanggal bukti potong. NPWP/NIK pelanggan kini bisa diisi.
- **Laporan keuangan** (Akuntansi → Laporan Keuangan): Laba Rugi (satu periode, per bulan, banding periode sebelumnya atau tahun lalu, per cabang dan cost center), Neraca (dengan pembanding akhir tahun lalu dan pemeriksaan seimbang), Arus Kas metode tidak langsung (kelompok operasi/investasi/pendanaan otomatis dari kode akun, bisa diubah per akun di Bagan Akun), Perubahan Ekuitas. Ekspor Excel/PDF dengan kop toko. Angka akun menuju Buku Besar, lalu ke jurnalnya.
- **Tutup buku** (Akuntansi → Tutup Buku): checklist tutup bulan (jurnal/kas/faktur yang belum diposting, rekonsiliasi bank, penyusutan, jurnal berulang) lalu kunci periode; **tutup tahun** membuat jurnal penutup per cabang (pendapatan dan beban dinolkan ke Laba Ditahan) dan mengunci semua bulannya. Pembatalan tutup tahun membalik jurnal penutupnya. **Membuka kembali periode yang dikunci kini butuh izin Tutup buku → Setujui** (bukan lagi Kunci periode → Ubah); semua tercatat di log.

### SDM (Fase 7, 12, dan 13)
- **Karyawan**: daftar, tambah/ubah/hapus, ekspor. Kode otomatis `KRY-0001`. Tautan opsional ke akun login (satu akun untuk satu karyawan); karyawan tanpa akun tetap bisa dicatat.
- **Data lengkap** (Fase 12): data pribadi, NIK KTP, NPWP, status PTKP, BPJS Kesehatan & Ketenagakerjaan, rekening gaji, kontak darurat, foto, keluarga, pendidikan, pengalaman kerja.
- **Kepegawaian**: cabang, departemen, jabatan, atasan langsung (tidak boleh melingkar), grade, status kerja Tetap (PKWTT) / Kontrak (PKWT) / Harian / Magang. **Riwayat kontrak** (status kerja ikut kontrak terbaru) dan **mutasi/promosi/demosi**; yang bertanggal ke depan diterapkan otomatis oleh `sdm:proses-harian` (00:50).
- **Halaman detail karyawan**: profil, keluarga & pendidikan, kontrak, mutasi, dokumen, aset tetap yang dipegang, bawahan.
- **Bagan organisasi** (SDM → Struktur Organisasi) dari relasi atasan: ciutkan/buka, cari, saring cabang/departemen.
- **Dokumen karyawan** (KTP, ijazah, sertifikat, dan lainnya) dengan berkas dan masa berlaku. **Pengingat** harian `sdm:pengingat` (07:05) untuk kontrak dan dokumen yang akan berakhir dalam 30 hari.
- **Offboarding** resign/PHK/kontrak habis: checklist pengembalian barang standar plus aset tetap yang dipegang; saat selesai, karyawan dan akun loginnya dinonaktifkan pada hari terakhir kerja.
- **Layanan Mandiri** (menu "Layanan Mandiri", ramah ponsel dengan bar navigasi bawah): profil sendiri, absen masuk/pulang, absensi per bulan, saldo cuti dan pengajuan cuti, slip gaji (menunggu modul Penggajian), **pengajuan lembur** (jam mulai–selesai, boleh lewat tengah malam, tidak boleh bentrok) dan **reimbursement** dengan foto/PDF bukti. Pengajuan disetujui/ditolak oleh SDM di SDM → Lembur / Reimbursement, atau lewat alur persetujuan `overtime` (kondisi jam lembur) / `reimbursement` (kondisi total) bila ada yang cocok. Bukti reimbursement hanya bisa dibuka pemiliknya dan SDM.
- **Departemen & Jabatan**: daftar, tambah/ubah/hapus, ekspor. Tidak bisa dihapus bila masih dipakai karyawan.
- **Shift & jadwal** (Fase 13): shift dengan toleransi telat/pulang cepat, istirahat, dan warna; **pola rotasi shift** (misal Pagi, Pagi, Siang, Siang, Libur) diterapkan ke banyak karyawan sekaligus; **jadwal bulanan** karyawan × tanggal; **hari libur** nasional/cabang (manual atau impor Excel/CSV). Hari kerja 5 atau 6 hari dan jam kerja bawaan di Pengaturan → SDM.
- **Absensi** (Fase 13): absen dari ponsel dengan **radius lokasi cabang** (titik + radius di Pengaturan → Cabang) dan **selfie** (wajib bila diatur; foto dikecilkan di browser dan disimpan privat). Menit telat dan pulang cepat dicatat. **Impor data mesin fingerprint** (Excel/CSV: PIN, tanggal & jam): scan pertama = masuk, terakhir = pulang, shift malam masuk ke tanggal mulai, izin/sakit/cuti tidak ditimpa, impor ulang tidak menggandakan. PIN mesin diisi di data karyawan.
- **Koreksi absensi** dari Layanan Mandiri (lupa absen, jam salah) dengan persetujuan SDM atau alur `attendance_correction`; jam yang disetujui langsung diterapkan. Batas hari ke belakang diatur.
- **Upah lembur**: aturan bertanggal berlaku di Pengaturan → SDM (bawaan PP 35/2021: upah sejam = gaji pokok ÷ 173; hari kerja jam pertama 1,5×, berikutnya 2×; hari libur untuk 5 atau 6 hari kerja), atau tarif tetap per jam; pembulatan dan lembur minimal. Gaji pokok dan hak lembur di data karyawan, hanya untuk pemegang izin penggajian.
- **Rekap absensi** (SDM → Rekap Absensi): per karyawan/departemen/cabang per periode (awal periode bisa tanggal 26, dst.): hari kerja, hadir, telat (kali/menit), pulang cepat, tidak absen pulang, izin, sakit, cuti, cuti tanpa upah, alpha, masuk di hari libur, jam kerja, lembur dan upah lembur. Rincian harian per karyawan; ekspor Excel/PDF. Siap dipakai Penggajian (Fase 14).
- **Cuti & izin** (Fase 13): aturan per jenis cuti (kategori, dibayar/tanpa upah, surat keterangan wajib setelah n hari, batas hari per pengajuan, masa kerja minimal, khusus laki-laki/perempuan, hitung hari kerja saja), **saldo tahunan per masa kerja**, tombol jenis cuti standar (UU 13/2003), surat dokter sebagai lampiran, **persetujuan lewat Pengaturan → Alur Persetujuan** (dokumen "Cuti & izin", kondisi jenis cuti/jumlah hari, tahap atasan langsung) dengan tahap lama jenis cuti sebagai cadangan, dan **kalender cuti tim**.
- Mesin persetujuan kini punya penyetuju **Atasan langsung pengaju** (cuti, lembur, reimbursement, koreksi absensi) dan kondisi berformat angka/pilihan (bukan hanya Rupiah).
- Absensi dan jadwal kini dicatat per karyawan (`employee_id`); layar lama berbasis akun login tetap berjalan.

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
- Penyusutan memakai bulan penuh sejak bulan perolehan (tanpa prorata harian); revaluasi, penurunan nilai, dan pemindahan aset antar cabang belum ada.
- Pembelian aset lewat faktur pembelian/tagihan belum otomatis membuat aset; catat asetnya di Aset Tetap dengan akun pembayaran Hutang Usaha bila perlu.
- PPh baru untuk tagihan pembelian manual (bukan faktur pembelian barang). e-Bupot dan e-Faktur dikirim manual dari berkas ekspor; format kolomnya perlu dicek dengan versi aplikasi DJP yang dipakai.
- Laporan keuangan dihitung langsung dari jurnal (belum ada tabel ringkasan); untuk data bertahun-tahun mungkin perlu cache/queue.
- Halaman lama Piutang Pelanggan / Hutang Supplier masih hanya menampilkan penjualan kasir / faktur pembelian; faktur manual dan saldo awal ada di Faktur Penjualan / Tagihan Pembelian, dan semuanya muncul bersama di Pelunasan, umur, dan kartu.
- Rekonsiliasi bank menghitung pencatatan buku yang belum cocok hanya di dalam periode rekening koran; pencatatan lama sebelum rekonsiliasi pertama tidak ikut.
- Batas kredit pelanggan dan pengingat ke pelanggan (bukan ke pengguna) direncanakan di Fase 15.
- **Jurnal untuk transaksi lama**: transaksi sebelum jurnal otomatis menyala tidak dijurnal. Catat posisinya lewat Saldo Awal per tanggal mulai.
- Jurnal otomatis belum diberi cost center (hanya jurnal manual dan jurnal berulang); rencana: cost center bawaan per gudang/cabang.
- Biaya MDR kartu/QRIS belum dicatat otomatis (akun per metode pembayaran sudah bisa dipisah).

### SDM
- **Penggajian**: komponen gaji, potongan, hitung dari absensi, slip gaji, dan jurnal beban gaji (rencana event `PayrollPosted` → D Beban Gaji / K Kas atau Hutang Gaji).
- Absensi, Jadwal, dan Cuti belum bisa untuk karyawan tanpa akun login, karena masih berbasis `user_id`. Rencana: pindah ke `employee_id` dengan migrasi data dari `employees.user_id`.
- Lembur dan reimbursement yang disetujui belum dibayar otomatis: upah lembur dihitung di Fase 13/14, reimbursement dibayar lewat Kas & Bank (atau penggajian nanti).
- Absensi dari Layanan Mandiri belum memeriksa radius lokasi atau foto selfie (Fase 13).

### Laporan
- Laporan Pembelian (per supplier, produk, periode), Laporan Stok (per gudang, nilai persediaan), Laba Kotor, Kinerja Kasir.

### Fondasi (lanjutan Fase 8)
- Server: queue worker (systemd) dan cron scheduler belum dipasang (jurnal berulang, balik otomatis, dan backup harian butuh scheduler); email masih `MAIL_MAILER=log`; batas unggah PHP 2 MB. Langkahnya di [DEPLOY.md](DEPLOY.md).
- Impor produk masih memakai halaman lamanya (semua-atau-tidak-sama-sekali); belum dipindah ke kerangka impor baru.
- Persetujuan baru dipakai purchase order dan jurnal manual. Retur penjualan/pembelian, stok opname, dan cuti masih memakai persetujuan lamanya (cuti punya alur bertahap sendiri).
- Transfer stok antar cabang tercatat di cabang gudang asal dan dijurnal di kedua cabang lewat Rekening Antar Cabang; status kirim/terima dan barang dalam perjalanan direncanakan di Fase 16.
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
