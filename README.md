# Aplikasi Kasir (POS) — Tahap 1: Setup, Autentikasi, dan Role

Isi paket ini adalah **lapisan kode** yang ditimpakan ke proyek Laravel baru + Breeze + Spatie Permission.
Tahap 1 mencakup: login/logout, tiga peran (Super Admin, Admin, Kasir), matriks izin, manajemen pengguna,
layout berbahasa Indonesia dengan sidebar berbasis hak akses, serta tes otomatis.

Versi yang dipakai: Laravel 13 (PHP 8.3+), Laravel Breeze (stack Blade + Tailwind + Alpine), `spatie/laravel-permission`.

## 1. Struktur database

| Tabel | Sumber | Keterangan |
|---|---|---|
| `users` | bawaan Laravel + migrasi Tahap 1 | Kolom tambahan: `phone`, `is_active` (indeks), `last_login_at`, `deleted_at` (soft delete) |
| `roles`, `permissions` | Spatie | Peran dan izin (`guard_name = web`) |
| `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Spatie | Relasi pengguna–peran–izin |
| `sessions`, `cache`, `password_reset_tokens`, `jobs` | bawaan Laravel | Sesi login dan infrastruktur |

Pengguna tidak dihapus permanen (soft delete) supaya riwayat transaksi yang kelak merujuk `user_id` tidak rusak.
Email tetap unik termasuk untuk akun yang sudah dihapus.

## 2. Instalasi (dari nol)

Prasyarat: PHP 8.3+, Composer, Node.js 20+, MySQL/MariaDB.
Jalankan di Git Bash / WSL / terminal macOS-Linux (perintah `rm` dan `cp` di bawah).

```bash
# 1) Proyek Laravel + Breeze (Blade) + Spatie
composer create-project laravel/laravel pos-toko
cd pos-toko
composer require laravel/breeze --dev
php artisan breeze:install blade
composer require spatie/laravel-permission
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"

# 2) Timpa dengan file paket ini (ganti ../pos-toko-tahap1 sesuai lokasi Anda)
cp -R ../pos-toko-tahap1/. .

# 3) Hapus file Breeze yang tidak dipakai (registrasi publik, reset sandi, verifikasi email, profil)
rm -f app/Http/Controllers/Auth/{RegisteredUserController,PasswordResetLinkController,NewPasswordController,EmailVerificationPromptController,VerifyEmailController,EmailVerificationNotificationController,ConfirmablePasswordController,PasswordController}.php
rm -f app/Http/Controllers/ProfileController.php app/Http/Requests/ProfileUpdateRequest.php
rm -f resources/views/auth/{register,forgot-password,reset-password,verify-email,confirm-password}.blade.php
rm -rf resources/views/profile resources/views/layouts/navigation.blade.php
rm -rf tests/Feature/Auth tests/Feature/ProfileTest.php tests/Feature/ExampleTest.php tests/Unit/ExampleTest.php

# 4) Zona waktu Jakarta
sed -i "s/'timezone' => 'UTC'/'timezone' => 'Asia\/Jakarta'/" config/app.php
```

Ubah `.env` (buat database `pos_toko` lebih dulu, mis. lewat phpMyAdmin):

```dotenv
APP_NAME="Toko Saya"
APP_LOCALE=id
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=id_ID

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pos_toko
DB_USERNAME=root
DB_PASSWORD=
```

Lalu:

```bash
php artisan optimize:clear
php artisan migrate --seed
npm install
npm run build        # atau: npm run dev  (saat pengembangan)
php artisan serve    # buka http://127.0.0.1:8000
```

Akun contoh (kata sandi semuanya `password` — **ganti sebelum production**):

| Email | Peran |
|---|---|
| admin@example.com | Super Admin |
| manajer@example.com | Admin (tidak dibuat di production) |
| kasir@example.com | Kasir (tidak dibuat di production) |

## 3. Menjalankan tes

```bash
php artisan test
```

Tes memakai SQLite in-memory (bawaan `phpunit.xml`), jadi ekstensi `pdo_sqlite` harus aktif.
Cakupan Tahap 1: login/logout, akun nonaktif dan terhapus, hak akses per peran, CRUD pengguna,
aturan "minimal satu Super Admin aktif", serta format Rupiah/tanggal.

## 4. Matriks hak akses

| Izin | Super Admin | Admin | Kasir |
|---|:-:|:-:|:-:|
| view-dashboard | ✓ | ✓ | – |
| manage-products, manage-categories, manage-suppliers, manage-customers | ✓ | ✓ | – |
| view-products, view-stock, print-receipt | ✓ | ✓ | ✓ |
| create-sale, process-returns | ✓ | ✓ | ✓ |
| view-sales | ✓ | ✓ | – |
| view-own-sales | ✓ | – | ✓ |
| void-sale | ✓ | ✓ | – |
| create-purchase, view-purchases | ✓ | ✓ | – |
| manage-stock, stock-opname | ✓ | ✓ | – |
| manage-payables, manage-receivables | ✓ | ✓ | – |
| view-reports | ✓ | ✓ | – |
| manage-users, manage-settings | ✓ | – | – |

Mengubah matriks: edit `database/seeders/RolePermissionSeeder.php`, lalu
`php artisan db:seed --class=RolePermissionSeeder` (aman dijalankan berulang).

## 5. Keputusan desain yang perlu Anda tahu

- **Tailwind CSS** dipakai (bawaan Breeze), bukan Bootstrap.
- **Tidak ada registrasi publik.** Akun dibuat Super Admin lewat menu Pengguna. Reset sandi via email,
  verifikasi email, dan halaman profil Breeze sengaja dibuang dulu karena belum ada SMTP; bisa ditambahkan lagi kapan saja.
- **Lima izin tambahan** di luar daftar di prompt, karena peran Kasir membutuhkannya:
  `view-products`, `view-stock`, `view-own-sales`, `print-receipt`, `process-returns`.
- **Admin boleh `create-sale`** (pemilik toko kecil sering ikut melayani). Cabut di seeder bila tidak diinginkan.
- **Halaman Dashboard terbuka untuk semua pengguna login** sebagai halaman awal; widget laba/omzet nanti dibungkus `@can('view-dashboard')`, jadi Kasir tidak melihat keuntungan.
- **Satu pengguna = satu peran.**
- **Sidebar dibaca dari `config/menu.php`.** Item baru muncul otomatis begitu route-nya terdaftar di tahap berikutnya
  (nama route sudah ditetapkan: `pos.index`, `products.index`, `purchases.index`, dst.).
- **Pelanggaran aturan bisnis** dilempar sebagai `AturanBisnisException` dan tampil sebagai notifikasi error.
  Pola yang sama dipakai untuk "Stok tidak mencukupi." pada Tahap 5.
- Super Admin melewati semua izin lewat `Gate::before`; aturan yang harus berlaku untuk semua orang
  (tidak boleh hapus akun sendiri, minimal satu Super Admin aktif) dijaga di `UserService`.

## 6. Daftar file

```
bootstrap/app.php                          alias middleware Spatie, cek akun aktif, handler AturanBisnisException
config/menu.php                            definisi sidebar
app/Enums/RoleName.php                     nama peran
app/Exceptions/AturanBisnisException.php
app/Models/User.php                        HasRoles + SoftDeletes
app/Policies/UserPolicy.php
app/Providers/AppServiceProvider.php       Gate::before, @rupiah, @tanggal
app/Services/UserService.php               logika bisnis pengguna (dalam DB::transaction)
app/Support/{Format,Menu}.php
app/Http/Middleware/EnsureUserIsActive.php
app/Http/Controllers/{DashboardController,UserController}.php
app/Http/Controllers/Auth/AuthenticatedSessionController.php
app/Http/Requests/{StoreUserRequest,UpdateUserRequest}.php
app/Http/Requests/Auth/LoginRequest.php
database/migrations/2026_09_28_000001_add_pos_columns_to_users_table.php
database/factories/UserFactory.php
database/seeders/{DatabaseSeeder,RolePermissionSeeder,UserSeeder}.php
routes/{web,auth}.php
lang/id/*.php, lang/id.json
resources/views/{layouts,auth,users,partials,errors}/…, dashboard.blade.php
tests/{Concerns,Feature,Unit}/…
```

## 7. Cek manual setelah instalasi

1. Login `admin@example.com` → sidebar menampilkan Dashboard dan Pengguna.
2. Tambah pengguna baru berperan Kasir, lalu logout dan login dengan akun itu.
3. Sebagai Kasir, buka `/pengguna` → halaman "Akses ditolak" (403).
4. Nonaktifkan akun Kasir dari akun Super Admin → Kasir tidak bisa login lagi.
5. Coba hapus akun Anda sendiri → muncul notifikasi penolakan.

Tahap 1 selesai bila semua langkah di atas berjalan dan `php artisan test` hijau.
Tahap berikutnya: **Tahap 2 — Master data** (Produk, Kategori, Satuan, Supplier, Pelanggan).
