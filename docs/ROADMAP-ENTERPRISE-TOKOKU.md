# Toko-ku Enterprise — Fase 8 s/d 19

> Lanjutan dari docs/REDESIGN-TOKOKU.md (Fase 0–7 sudah selesai).
> Cara pakai:
> 1. Simpan sebagai `docs/ROADMAP-ENTERPRISE-TOKOKU.md`.
> 2. Tambahkan bagian **"Tambahan Aturan CLAUDE.md"** ke CLAUDE.md.
> 3. Jalankan prompt per fase, pakai Plan Mode untuk setiap fase. Commit + tag git setiap fase (misal `v-fase-8`).
> 4. Urutan penting: Fase 8 adalah fondasi yang dipakai semua fase sesudahnya.

---

## Tambahan Aturan CLAUDE.md

```markdown
## Aturan Enterprise
- Nominal uang: kolom decimal(18,2), kuantitas decimal(18,4). Jangan pakai float. Gunakan helper/Money cast.
- Semua transaksi yang menyentuh stok, kas, atau jurnal wajib dalam DB::transaction dan menggunakan lockForUpdate bila perlu.
- Dokumen bisnis punya status: draft → submitted → approved → posted → cancelled. Dokumen posted tidak boleh diedit/dihapus; koreksi lewat pembatalan/jurnal balik (reversal).
- Jurnal wajib seimbang (total debit = total kredit) dan tidak boleh diposting ke periode yang sudah dikunci.
- Semua tabel transaksi punya company_id/branch_id (scope via global scope), created_by, updated_by, soft deletes.
- Tarif pajak, BPJS, PTKP, TER PPh 21, UMR: SELALU disimpan sebagai data yang bisa diatur di Pengaturan (dengan tanggal berlaku), JANGAN hardcode.
- Proses berat (laporan besar, payroll massal, import, email) lewat Queue job.
- Setiap modul baru wajib: permission seeder, entri di config/menu.php, feature test untuk alur utama.
```

---

## Peta Fitur & Prioritas

| Prioritas | Area | Fitur |
|---|---|---|
| Wajib | Fondasi | Approval workflow, notifikasi, lampiran dokumen, kunci periode, import/export massal, scope cabang |
| Wajib | Akuntansi | COA bertingkat, jurnal & reversal, auto-posting, buku besar, kas & bank, piutang, hutang, laporan keuangan, tutup buku |
| Wajib | HR | Data karyawan lengkap, kontrak, absensi & shift, cuti, lembur, payroll + BPJS + PPh 21, slip gaji |
| Penting | Akuntansi | Aset tetap & penyusutan, anggaran vs realisasi, pajak (PPN, PPh 21/23), rekonsiliasi bank, cost center |
| Penting | HR | Employee Self Service, kasbon, reimbursement, penilaian kinerja, THR |
| Penting | Penjualan | Penawaran, Sales Order, Surat Jalan, Faktur, limit kredit, harga per pelanggan |
| Penting | Inventori | Varian, batch & kedaluwarsa, serial number, HPP FIFO/average, reorder otomatis, cetak label barcode |
| Penting | Keamanan | 2FA, riwayat login, kebijakan password, kelola sesi |
| Opsional | CRM | Member, poin loyalti, voucher, mesin promo |
| Opsional | Integrasi | REST API, webhook, QRIS/payment gateway, WhatsApp, marketplace |
| Opsional | Lanjutan | Multi-perusahaan, report builder, laporan terjadwal, mode offline kasir (PWA), rekrutmen |

---

## Prompt Bertahap

### Fase 8 — Fondasi Enterprise

```
Baca CLAUDE.md dan docs/ROADMAP-ENTERPRISE-TOKOKU.md. Kerjakan Fase 8 (fondasi yang dipakai semua modul berikutnya):

1. Scope organisasi: pastikan tabel companies dan branches ada. Tambahkan company_id/branch_id ke tabel transaksi yang belum punya (migration aditif, isi data lama ke cabang default). Buat trait BelongsToBranch + global scope berdasarkan cabang aktif di topbar. User bisa diberi akses ke beberapa cabang.
2. Approval workflow engine yang bisa dikonfigurasi:
   - Tabel approval_flows (dokumen, kondisi misal total > Rp 5.000.000, cabang), approval_steps (urutan, role atau user approver), approvals (riwayat: approve/reject/catatan).
   - Trait HasApproval untuk model dokumen. Halaman "Persetujuan Saya" (kotak masuk approval) dan halaman pengaturan alur.
3. Notification center: notifikasi in-app (ikon lonceng di topbar, tandai dibaca) + email, via Laravel Notifications & queue. Preferensi notifikasi per user.
4. Lampiran dokumen: trait HasAttachments, komponen upload drag & drop, preview gambar/PDF.
5. Status dokumen & penomoran: trait HasDocumentStatus (draft/submitted/approved/posted/cancelled) memakai Penomoran Dokumen dari Pengaturan.
6. Kunci periode: tabel fiscal_periods (bulan, status open/closed). Middleware/validator yang menolak transaksi ke periode tertutup.
7. Framework import/export: import Excel dengan template download, preview, validasi per baris, laporan error; export Excel/PDF via queue untuk data besar.
8. Pasang queue (database atau redis) dan scheduler; dokumentasikan cara menjalankannya di docs/DEPLOY.md.
Tulis feature test untuk approval engine dan kunci periode.
```

### Fase 9 — Akuntansi Inti

```
Kerjakan Fase 9, lengkapi modul Akuntansi (ubah flag coming_soon menjadi enabled untuk halaman yang selesai):
1. Bagan Akun (COA): bertingkat (header/detail), tipe (Aset, Kewajiban, Ekuitas, Pendapatan, HPP, Beban), saldo normal, akun kas/bank, status aktif. Tampilan tree + import Excel. Seeder COA standar retail Indonesia.
2. Saldo awal: input saldo awal per akun, persediaan, piutang, dan hutang per pihak.
3. Jurnal Umum: multi-baris, wajib seimbang, cost center/departemen opsional per baris, lampiran, approval, posting, reversal (jurnal balik otomatis), jurnal berulang (template bulanan via scheduler).
4. Pemetaan Akun Otomatis (Pengaturan Akuntansi): akun default untuk penjualan, diskon, PPN keluaran/masukan, HPP, persediaan, hutang, piutang, kas per metode pembayaran, beban gaji, dll.
5. Auto-posting: listener untuk SaleCompleted, SalesReturn, GoodsReceived, PurchaseReturn, pembayaran, stok opname (selisih), dan mutasi stok. Setiap jurnal otomatis menyimpan referensi ke dokumen sumber (bisa diklik dari jurnal ke dokumen dan sebaliknya).
6. Buku Besar per akun (filter periode, cabang, cost center) dan Neraca Saldo.
7. Cost center / departemen sebagai dimensi laporan.
Feature test: jurnal seimbang, reversal, auto-posting penjualan dan pembelian.
```

### Fase 10 — Kas & Bank, Piutang, Hutang

```
Kerjakan Fase 10:
1. Kas & Bank: daftar rekening, penerimaan kas, pengeluaran kas (dengan approval), transfer antar rekening, kas kecil (petty cash) dengan pengisian ulang.
2. Rekonsiliasi bank: import mutasi bank (CSV/Excel), pencocokan otomatis berdasarkan nominal & tanggal, pencocokan manual, laporan rekonsiliasi.
3. Piutang: faktur penjualan kredit, pembayaran piutang (bisa sebagian, satu pembayaran untuk banyak faktur), uang muka pelanggan, nota kredit, umur piutang (0-30/31-60/61-90/>90 hari), kartu piutang per pelanggan, pengingat jatuh tempo.
4. Hutang: faktur pembelian dari Penerimaan Barang, pembayaran hutang (sebagian/gabungan), uang muka supplier, nota debit, umur hutang, kartu hutang, jadwal pembayaran.
5. Giro/cek mundur (opsional): status terima, cair, tolak.
Semua otomatis membuat jurnal lewat pemetaan akun Fase 9.
```

### Fase 11 — Aset, Anggaran, Pajak, Laporan Keuangan, Tutup Buku

```
Kerjakan Fase 11:
1. Aset Tetap: kategori aset (umur ekonomis, metode garis lurus/saldo menurun, akun terkait), daftar aset dengan lokasi & penanggung jawab, penyusutan otomatis bulanan via scheduler (jurnal otomatis), pelepasan/penjualan aset (laba/rugi), kartu aset.
2. Anggaran: anggaran per akun per bulan per cabang/cost center, laporan anggaran vs realisasi dengan persentase dan peringatan jika melebihi.
3. Pajak (tarif dari Pengaturan dengan tanggal berlaku):
   - PPN: laporan PPN keluaran & masukan, rekap per masa, export data faktur dalam format yang bisa diimpor ke sistem pajak DJP (buat format export-nya bisa disesuaikan karena format DJP dapat berubah).
   - PPh 23 & PPh 4(2) atas pembelian jasa: pemotongan di faktur pembelian, rekap bukti potong.
4. Laporan Keuangan: Laba Rugi (bulanan, komparatif, per cabang), Neraca, Arus Kas (metode tidak langsung), Perubahan Ekuitas. Semua bisa export PDF/Excel dengan kop perusahaan. Drill-down dari angka ke buku besar ke jurnal.
5. Tutup Buku: checklist tutup bulan (jurnal draft, rekonsiliasi, penyusutan), kunci periode, tutup tahun (jurnal penutup laba ditahan otomatis), buka kembali periode hanya oleh role tertentu dengan log.
```

### Fase 12 — HR Inti

```
Kerjakan Fase 12, lengkapi modul HR:
1. Data karyawan lengkap: data pribadi, NIK KTP, NPWP, status PTKP, BPJS Kesehatan & Ketenagakerjaan, rekening bank, kontak darurat, keluarga, pendidikan, pengalaman, foto.
2. Kepegawaian: cabang, departemen, jabatan, atasan langsung, grade, tanggal masuk, status (PKWT/PKWTT/harian/magang), riwayat kontrak dengan pengingat kontrak habis, riwayat mutasi/promosi.
3. Struktur organisasi: tampilan bagan organisasi interaktif dari relasi atasan.
4. Dokumen karyawan: upload dan masa berlaku (pengingat).
5. Offboarding: resign/PHK, checklist pengembalian aset, nonaktifkan akun user otomatis.
6. Employee Self Service (ESS): karyawan yang punya akun login melihat profil, slip gaji, saldo cuti, absensi, dan mengajukan cuti/lembur/reimbursement. Layout ESS ramah mobile.
```

### Fase 13 — Absensi, Shift, Lembur, Cuti

```
Kerjakan Fase 13:
1. Shift & jadwal: master shift (jam masuk/pulang, toleransi telat, istirahat), jadwal per karyawan (kalender, rotasi), hari libur nasional (input manual / import).
2. Absensi: clock in/out dari ESS dengan geolokasi (radius kantor/cabang) dan foto selfie, import data mesin fingerprint (Excel/CSV), koreksi absensi dengan approval, rekap telat, pulang cepat, alpha.
3. Lembur: pengajuan + approval, hitung jam lembur, perhitungan upah lembur dengan rumus yang bisa diatur.
4. Cuti & izin: jenis cuti (tahunan, sakit dengan surat, melahirkan, khusus, tanpa upah) dengan aturan yang bisa diatur, saldo cuti tahunan otomatis berdasarkan masa kerja, approval berjenjang lewat approval engine, kalender cuti tim.
5. Laporan: rekap absensi bulanan per karyawan/departemen, siap dipakai payroll.
```

### Fase 14 — Payroll

```
Kerjakan Fase 14:
1. Komponen gaji: pendapatan tetap/tidak tetap, tunjangan, potongan; rumus per komponen (nominal, persentase, per hari hadir, per jam lembur). Template komponen per jabatan/grade.
2. BPJS Kesehatan & Ketenagakerjaan (JHT, JKK, JKM, JP): porsi perusahaan dan karyawan, batas atas upah — semua tarif dari Pengaturan dengan tanggal berlaku.
3. PPh 21: metode tarif efektif (TER) bulanan dan perhitungan tahunan di masa pajak terakhir, PTKP sesuai status. Tabel tarif disimpan sebagai data yang bisa diubah. Opsi gross / gross-up / net.
4. Proses payroll per periode: tarik data absensi, lembur, cuti tanpa upah, kasbon; preview; approval; posting (jurnal beban gaji & hutang gaji otomatis); kunci.
5. Kasbon/pinjaman karyawan dengan cicilan otomatis potong gaji. Reimbursement dengan approval dan bukti.
6. THR otomatis sesuai masa kerja (rumus bisa diatur), bonus.
7. Output: slip gaji PDF (dikirim email terenkripsi password atau via ESS), file transfer bank (CSV), laporan rekap gaji, rekap BPJS, rekap PPh 21, bukti potong tahunan.
Feature test untuk perhitungan dengan beberapa skenario (status PTKP berbeda, karyawan masuk tengah bulan, lembur, potongan).
Catatan: tampilkan peringatan di halaman Pengaturan Payroll agar admin memverifikasi tarif terbaru sesuai regulasi.
```

### Fase 15 — Penjualan B2B, CRM & Promo

```
Kerjakan Fase 15:
1. Penjualan B2B: Penawaran (quotation) → Sales Order → Surat Jalan (pengiriman parsial) → Faktur → Pembayaran. Cetak dokumen dengan kop perusahaan, kirim via email.
2. Pelanggan: grup pelanggan, daftar harga per grup/pelanggan, termin pembayaran, limit kredit (blokir/approval jika melebihi), riwayat transaksi.
3. Member & loyalti: kartu member (scan di kasir), poin per transaksi, penukaran poin, level member.
4. Mesin promo: diskon persen/nominal, beli X gratis Y, diskon bertingkat per kuantitas, bundling harga, happy hour (jam & hari), voucher/kode kupon dengan kuota, berlaku per cabang/periode. Terintegrasi di Kasir dan Sales Order dengan prioritas promo yang jelas.
5. Komisi sales (opsional): persentase per produk/kategori, laporan komisi.
```

### Fase 16 — Inventori Lanjutan

```
Kerjakan Fase 16:
1. Varian produk (ukuran, warna) dengan SKU & barcode per varian; produk paket/bundling yang mengurangi stok komponen.
2. Multi-satuan dengan konversi (pcs, pak, dus) dan harga per satuan.
3. Batch/lot & tanggal kedaluwarsa dengan pengeluaran FEFO, peringatan mendekati kedaluwarsa. Serial number opsional per produk.
4. Metode HPP yang bisa dipilih (FIFO / rata-rata tertimbang), penilaian persediaan per tanggal.
5. Minimum/maksimum stok per gudang, saran pemesanan ulang otomatis menjadi draft PO.
6. Transfer stok antar cabang dengan status kirim/terima, barang dalam perjalanan.
7. Stok opname dengan scan barcode dari HP, freeze stok saat opname, approval penyesuaian.
8. Cetak label barcode/harga (pilih ukuran label), import produk massal.
9. Laporan: nilai persediaan, perputaran stok, slow/dead moving, kartu stok per batch.
```

### Fase 17 — Keamanan & Multi-Perusahaan

```
Kerjakan Fase 17:
1. Keamanan: 2FA (TOTP, wajib untuk role tertentu), kebijakan password (panjang, kedaluwarsa), riwayat login (IP, perangkat), kelola & paksa logout sesi aktif, kunci akun setelah gagal login berkali-kali, opsional pembatasan IP.
2. Audit trail: tampilkan perubahan before/after per record, filter per user/modul/tanggal, tidak bisa dihapus.
3. Hak akses data: batasi data per cabang dan opsional "hanya data milik sendiri" per permission.
4. Multi-perusahaan (opsional, diskusikan dulu dengan saya): satu instalasi untuk beberapa perusahaan dengan COA, pengaturan, dan data terpisah; konsolidasi laporan keuangan.
5. Backup otomatis harian (spatie/laravel-backup) ke storage eksternal dengan notifikasi gagal.
```

### Fase 18 — Integrasi

```
Kerjakan Fase 18 (tanyakan dulu provider mana yang saya pakai sebelum implementasi):
1. REST API dengan Laravel Sanctum: token per aplikasi dengan scope, rate limit, dokumentasi API otomatis.
2. Webhook keluar untuk event utama (transaksi selesai, stok menipis, faktur dibayar) dengan retry dan log.
3. Payment gateway untuk QRIS/VA/e-wallet di Kasir dan link pembayaran faktur, status otomatis lewat callback.
4. WhatsApp gateway: kirim struk digital, pengingat jatuh tempo piutang, slip gaji, notifikasi approval.
5. Sinkronisasi marketplace/toko online (opsional): stok dan pesanan, dengan arsitektur adapter per channel.
Semua kredensial disimpan terenkripsi di Pengaturan Integrasi, bukan di kode.
```

### Fase 19 — Laporan Lanjutan, Performa & Kesiapan Produksi

```
Kerjakan Fase 19:
1. Dashboard per peran (Pemilik, Akuntan, HRD, Kepala Gudang, Kasir) dengan widget yang bisa diatur.
2. Laporan terjadwal: user memilih laporan + filter, dikirim email harian/mingguan/bulanan dalam PDF/Excel.
3. Report builder sederhana (opsional): pilih sumber data, kolom, filter, grouping, simpan sebagai laporan favorit.
4. Performa: audit query N+1, tambah index, cache pengaturan & menu, pagination berbasis cursor untuk tabel besar, uji dengan data dummy besar (misal 1 juta baris transaksi).
5. Mode offline Kasir (PWA): transaksi tersimpan lokal saat internet putus dan sinkron otomatis saat online (opsional, rencanakan dulu).
6. Kualitas: lengkapi feature test semua modul, pasang Laravel Pint & Larastan, GitHub Actions CI, Laravel Pulse/Horizon untuk monitoring.
7. Dokumentasi: docs/DEPLOY.md (server, queue worker, scheduler, backup), panduan pengguna per modul di docs/user-guide/.
```

---

## Tips

- Fase 14 (Payroll) dan Fase 11 (Pajak) menyangkut regulasi. Setelah selesai, cocokkan hasil hitungan dengan konsultan pajak/akuntan Anda pada beberapa contoh karyawan dan transaksi nyata.
- Jika satu fase terlalu besar, minta Claude Code memecahnya: "Kerjakan poin 1–3 dari Fase 9 dulu."
- Sebelum fase yang mengubah banyak tabel (8, 16, 17), backup database dan coba di salinan data produksi.
