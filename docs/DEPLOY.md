# Deploy & Operasional Toko-ku

Panduan memasang versi baru, menjalankan queue worker dan scheduler, serta memeriksa kesehatan
sistem. Ditulis untuk server saat ini; sesuaikan path bila berbeda.

| Hal | Nilai di server |
|---|---|
| Folder aplikasi | `/var/www/toko-ku` |
| PHP | 8.3 (FPM, `php8.3-fpm`) |
| Web server | Nginx (`client_max_body_size 100M`) |
| Database | MySQL `toko_ku` |
| Pengguna web | `www-data` |
| Queue | `QUEUE_CONNECTION=database` (tabel `jobs`), tanpa Redis |
| Cache & session | database |

Status scheduler, queue worker, antrean email/ekspor, job gagal, pengirim email, dan batas unggah
bisa dilihat di **Pengaturan → Sistem** (izin `settings.system.view`).

---

## 1. Memasang versi baru

Jalankan berurutan dari folder aplikasi. Langkah yang tidak relevan (misal tidak ada perubahan
`composer.lock`) boleh dilewati.

```bash
cd /var/www/toko-ku

# 1. Cadangkan database dulu
mysqldump -u root -p toko_ku > ~/toko_ku-$(date +%Y%m%d-%H%M).sql

# 2. Mode perawatan
sudo -u www-data php artisan down

# 3. Ambil kode (contoh: branch fase)
git merge --ff-only fase-8

# 4. Autoload class baru (paket PHP: `composer install` hanya bila composer.lock berubah)
composer dump-autoload --no-scripts

# 5. Database dan izin baru (seeder tidak menimpa hak akses yang sudah diatur)
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --class=RolePermissionSeeder --force

# 6. Aset frontend (`npm install` dulu hanya bila package.json berubah)
npm run build

# 7. Bersihkan cache, lalu minta worker memuat ulang kode baru
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:restart

# 8. Selesai
sudo -u www-data php artisan up
```

`queue:restart` tidak menghentikan worker secara paksa: worker menyelesaikan job yang sedang
berjalan, lalu systemd menyalakannya lagi dengan kode baru.

---

## 2. Queue worker

### Untuk apa
- **Email notifikasi** (permintaan persetujuan, hasil persetujuan, ekspor siap).
- **Ekspor data besar** (lebih dari 3.000 baris) yang dibuat di latar belakang lalu muncul di
  **Ekspor Saya**.

Notifikasi di lonceng aplikasi **tidak** butuh worker; isinya langsung tersimpan. Selama worker
belum berjalan, email menunggu di tabel `jobs`, dan ekspor besar tetap dibuat langsung (seperti
sebelum Fase 8). Saat worker pertama kali dinyalakan, email yang menumpuk ikut terkirim.

### Memasang sebagai layanan systemd

Buat berkas `/etc/systemd/system/toko-ku-queue.service`:

```ini
[Unit]
Description=Toko-ku queue worker
After=network.target mysql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/toko-ku
ExecStart=/usr/bin/php artisan queue:work database --queue=default --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Lalu:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now toko-ku-queue
sudo systemctl status toko-ku-queue      # harus "active (running)"
journalctl -u toko-ku-queue -f          # melihat log worker
```

`--max-time=3600` membuat worker berhenti sendiri tiap jam (lalu dinyalakan lagi oleh systemd)
supaya pemakaian memori tetap kecil.

### Job yang gagal
- Daftar terakhir tampil di Pengaturan → Sistem, lengkap dengan tombol **Coba lagi semua** dan
  **Kosongkan**.
- Lewat terminal: `sudo -u www-data php artisan queue:failed`, `queue:retry all`, `queue:flush`.
- Job gagal yang lebih tua dari 30 hari dihapus otomatis oleh scheduler.

---

## 3. Scheduler

### Tugas terjadwal (`routes/console.php`)

| Waktu | Tugas |
|---|---|
| Setiap menit | Tanda hidup scheduler (untuk Pengaturan → Sistem) |
| 01:30 | Backup database (`backup:database`, menyimpan 14 berkas terbaru) |
| 02:00 | Hapus log aktivitas > 365 hari (`activitylog:clean`) |
| 02:15 | Hapus hasil impor > 30 hari dan berkas ekspor kedaluwarsa (`model:prune`) |
| 02:30 | Hapus notifikasi yang sudah dibaca > 90 hari |
| 02:45 | Hapus job gagal > 30 hari (`queue:prune-failed`) |

Lihat daftar lengkapnya dengan `sudo -u www-data php artisan schedule:list`.

### Memasang cron

Buat berkas `/etc/cron.d/toko-ku`:

```cron
* * * * * www-data cd /var/www/toko-ku && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Cron membaca berkas ini otomatis. Dalam 1–2 menit, Pengaturan → Sistem menampilkan scheduler
**Berjalan**. Tanpa cron, backup harian juga tidak berjalan.

---

## 4. Email

Saat ini `MAIL_MAILER=log`: isi email hanya ditulis ke `storage/logs/laravel.log`, tidak benar-benar
dikirim. Untuk mengirim email, isi pengaturan SMTP di `.env` server, misalnya:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.contoh.id
MAIL_PORT=587
MAIL_USERNAME=notifikasi@contoh.id
MAIL_PASSWORD=rahasia
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=notifikasi@contoh.id
MAIL_FROM_NAME="${APP_NAME}"
```

Setelah mengubah `.env`: `sudo -u www-data php artisan optimize:clear` lalu
`sudo -u www-data php artisan queue:restart`. Setiap pengguna bisa mematikan email per jenis
notifikasi di **Profil Saya → Notifikasi**.

---

## 5. Batas unggah berkas (lampiran)

Batas lampiran = terkecil dari `config/attachments.php` (10 MB) dan pengaturan PHP. Di server
saat ini PHP-FPM masih `upload_max_filesize = 2M`, jadi lampiran dibatasi **2 MB**. Untuk
menaikkan ke 10 MB, ubah `/etc/php/8.3/fpm/php.ini`:

```ini
upload_max_filesize = 10M
post_max_size = 12M
```

lalu `sudo systemctl reload php8.3-fpm`. Nginx sudah mengizinkan sampai 100 MB.

Berkas lampiran disimpan di `storage/app/private/attachments/` (tidak bisa dibuka langsung dari
web; hanya lewat aplikasi oleh pengguna yang boleh melihat dokumennya). Ikutkan folder ini saat
membackup server. Berkas ekspor besar ada di `storage/app/private/exports/` dan dihapus otomatis
setelah 7 hari.

---

## 6. Kembali ke versi sebelumnya

1. `sudo -u www-data php artisan down`
2. Pulihkan database dari dump yang dibuat sebelum deploy:
   `mysql -u root -p toko_ku < ~/toko_ku-YYYYMMDD-HHMM.sql`
3. Kembalikan kode: `git reset --hard <commit-sebelumnya>`, lalu `npm run build`,
   `sudo -u www-data php artisan optimize:clear` dan `queue:restart`.
4. `sudo -u www-data php artisan up`

Hindari `migrate:rollback` di server berisi data; memulihkan dari dump lebih aman.
