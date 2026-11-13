# Toko-ku — Aturan Proyek

## Konteks
Toko-ku adalah aplikasi manajemen toko (POS + back office) berbasis Laravel.
Modul: Dashboard, Kasir, Inventori, Pembelian, Laporan, Akuntansi, SDM, Pengguna & Hak Akses, Pengaturan.
Akuntansi dan SDM dibangun bertahap; strukturnya harus disiapkan sejak sekarang.
Spesifikasi lengkap: docs/REDESIGN-TOKOKU.md. Hasil audit, keputusan, dan rencana fase: docs/AUDIT.md.
Rencana Fase 8–19: docs/ROADMAP-ENTERPRISE-TOKOKU.md. Status modul: docs/ROADMAP.md. Deploy, queue, scheduler: docs/DEPLOY.md.

## Aturan kerja
- Jangan merusak fitur yang sudah berjalan. Sebelum mengubah, baca dulu kode yang ada.
- Migration bersifat aditif: jangan drop tabel/kolom berisi data tanpa persetujuan pemilik.
- Jangan ubah file .env, kredensial, atau konfigurasi produksi.
- Kerjakan per fase, ringkas perubahan di akhir, lalu tunggu konfirmasi pemilik.
- Jalankan `php artisan test` dan `npm run build` setelah perubahan besar.
- Bahasa antarmuka: Bahasa Indonesia. Nama kode (class, variabel, tabel): Bahasa Inggris.
- Format uang: Rupiah, "Rp 1.250.000". Tanggal: "08 Nov 2026". Zona waktu: Asia/Jakarta.

## Konvensi
- Controller per modul: app/Http/Controllers/{Modul}/... (contoh: Inventory/ProductController)
- View per modul: resources/views/{modul}/... (contoh: inventory/products/index.blade.php)
- Route per modul: routes/modules/{modul}.php, di-include dari routes/web.php, prefix & name sesuai modul.
- Komponen UI reusable: resources/views/components/ui/* (Blade components).
- Permission format: `{modul}.{fitur}.{aksi}` contoh `inventory.products.view`, `pos.transactions.create`.
- Semua izin terdaftar di config/permission-matrix.php. Izin baru: tambahkan di katalog, lalu `php artisan db:seed --class=RolePermissionSeeder` (tidak menimpa hak akses yang sudah diatur). Route baru wajib dijaga middleware `permission:` (dicek ProteksiRouteTest).
- Menu sidebar didefinisikan di config/menu.php, bukan hardcode di Blade.
- Pengaturan toko dibaca dengan `setting('key', $default)` dan disimpan dengan `setting([...])` (tabel settings, ter-cache). Jangan query tabel settings langsung.
- Nomor dokumen baru dibuat lewat `DocumentNumberService::next()` di dalam `DB::transaction()` yang menyimpan dokumennya. Jangan menulis awalan nomor secara hardcode.
- Validasi pakai Form Request. Logika bisnis berat di Service class (app/Services/{Modul}).
- Perubahan stok selalu lewat `StockService::catat()` dengan gudang (`Warehouse`); tanpa gudang = Gudang Utama. Cek konsistensi dengan `php artisan stok:periksa`. Stok masuk wajib membawa biaya (`unit_cost`, batch/seri bila ada); pembatalan/retur memakai `reverse_of`/`catatBarang(..., balikDokumen:)` supaya lot dan biaya asal kembali; produk paket lewat `catatBarang()`. HPP/nilai persediaan dari biaya mutasi (`total_cost`), bukan `purchase_price`. Baris dokumen per satuan lain diubah ke satuan dasar lewat `UnitConversion::toBase()`. Produk: `is_active` (aktif), `type` jual/bahan_baku (dijual di kasir), `is_purchasable` (dibeli). Produksi produk olahan lewat `ProductionService::produce()` (bahan = resep × jumlah ÷ `recipes.yield_quantity`).
- Tabel daftar dibangun dari satu kelas turunan `App\Exports\ListExport` (query, filter, sort, kolom), dipakai bersama oleh halaman, ekspor Excel, dan ekspor PDF.
- Penjualan dari Kasir dikirim ke `SaleService` dengan `payments[]` (satu baris per metode). Hitungan uang memakai bilangan bulat sen (`App\Support\Uang`), dan pratinjau di layar memakai `resources/js/pos/hitung.js` dengan aturan pembulatan yang sama.
- Jurnal akuntansi hanya lewat `App\Services\Accounting\JournalService` (debit = kredit, tidak bisa diubah; koreksi dengan jurnal pembalik). Akun untuk jurnal otomatis dibaca lewat `AccountMappingService` (`require('sales')`, `paymentAccount($metode)`): bawaannya kode di `config/accounting.php` (`mapping`), diubah pemilik di Pengaturan → Akuntansi. Jangan menulis kode akun langsung di service.
- Jurnal otomatis menyimpan dokumen sumber (`source` + `source_event`, unik) dan ditambahkan di `App\Listeners\CreateAutoJournal` + `AutoJournalService`. Halaman dokumen sumber menampilkan jurnalnya dengan `<x-ui.journal-links :document="$dokumen" />`.
- Uang masuk/keluar di luar penjualan/pembelian lewat `CashTransactionService` (rekening = `CashBankAccount`, menempel pada akun kas/bank). Pelunasan piutang/hutang (banyak dokumen, uang muka, nota, giro) lewat `SettlementService`; setiap alokasi tetap dicatat sebagai `payments` lewat `PaymentService`. Dokumen yang bisa dilunasi (penjualan, faktur pembelian, `Invoice` manual/saldo awal) dibaca lewat `OpenDocuments`.
- Aset tetap lewat `FixedAssetService` (perolehan, pelepasan) dan `DepreciationService` (penyusutan per periode, satu jurnal per cabang); hitungannya di `DepreciationCalculator`. Tarif PPh dibaca `TaxRateService::rateFor($kode, $tanggal)` (tabel `tax_rates`, berlaku per tanggal); jangan menulis persentase pajak di kode.
- Laporan keuangan dari `FinancialReportService` (struktur baris yang sama untuk layar, Excel `ReportTableExport`, dan PDF). Jurnal penutup tahun (`ClosingService`) tidak dihitung di Laba Rugi/Arus Kas, tetapi dihitung di Neraca.
- SDM: data karyawan lewat `EmployeeService` (kontrak `EmployeeContractService`, mutasi `EmployeeMovementService`). Pengajuan karyawan dari Layanan Mandiri (`ess.*`, data milik `auth()->user()->employee`) lewat `EmployeeRequestService`; dokumen baru jenis ini memakai trait `IsEmployeeRequest` + `HasApproval` + `HasDocumentStatus`.
- Absensi & jadwal per karyawan (`employee_id`; `user_id` disinkronkan otomatis). Hari kerja/libur dibaca lewat `WorkCalendar` (jadwal → hari libur `holidays` → libur mingguan `setting('sdm.workweek')`), bukan dihitung sendiri. Absen, scan fingerprint, dan koreksi lewat `AbsensiService`; upah lembur lewat `OvertimeCalculator` (aturan `overtime_rules` bertanggal berlaku); rekap untuk penggajian lewat `AttendanceRecapService`. Cuti lewat `CutiService` (aturan `jenis_cutis`, saldo `JenisCuti::quotaFor()`).
- Alur persetujuan: kolom kondisi di `config/approval.php` bisa diberi `formats` (number/option) dan `supervisor => true` untuk tahap "Atasan langsung" (dokumen menyediakan `approvalSupervisor()`).
- Laporan dari jurnal (buku besar, neraca saldo, laporan keuangan) hanya menghitung jurnal posted dan memakai `LedgerService` (saringan cabang/cost center, batas tanggal `LedgerService::bounds()`; jangan `<= 'Y-m-d'` pada `journal_date`).
- Event antar modul (misal `GoodsReceived`) memakai `ShouldDispatchAfterCommit`, supaya listener (Akuntansi) hanya menerima transaksi yang benar-benar tersimpan.

## Fondasi (Fase 8)
- Model transaksi memakai trait `BelongsToBranch` (company_id/branch_id terisi otomatis, data disaring ke cabang aktif). Query internal lintas cabang memakai `withoutGlobalScope(BranchScope::class)`; query builder dengan join menyaring sendiri lewat `CurrentBranch::scopeIds()`.
- Transaksi yang membukukan stok, kas, hutang-piutang, atau jurnal: rule `OpenPeriod` di Form Request dan `FiscalPeriodService::ensureOpen($tanggal)` di service.
- Persetujuan hanya lewat `ApprovalService` (trait `HasApproval`, daftar dokumen di `config/approval.php`); jangan membuat alur persetujuan sendiri.
- Dokumen baru memakai status baku `HasDocumentStatus` (draft/submitted/approved/posted/cancelled); dokumen posted tidak diubah, koreksi lewat pembatalan/jurnal balik.
- Lampiran: trait `HasAttachments` + `config/attachments.php` + `<x-ui.attachments>`. Notifikasi: turunan `App\Notifications\AppNotification` + `config/notification-types.php`. Impor massal: turunan `App\Imports\ImportDefinition` + `config/imports.php`.
- Proses berat (email, ekspor besar) lewat queue. Halaman yang memakai queue harus tetap berjalan bila worker belum hidup (lihat `QueueHealth`).

## Keputusan pemilik (08 Nov 2026, rincian di docs/AUDIT.md bagian 7)
- Admin boleh membuka Kasir (izin `create-sale`, nanti `pos.transactions.create`).
- `sales.subtotal` = harga kotor sebelum diskon per item: `grand_total = subtotal - item_discount - discount`. Pembelian disamakan di Fase 5b.
- Paket yang sudah disetujui: `@fontsource/inter`, `blade-ui-kit/blade-heroicons`, `spatie/laravel-activitylog` 4.12 (versi 5 butuh PHP 8.4), `chart.js`, `barryvdh/laravel-dompdf`, serta penghapusan `@tailwindcss/vite`. `spatie/laravel-backup` tidak dipakai karena versi untuk Laravel 13 butuh PHP 8.4; backup memakai perintah `mysqldump` sendiri.
- Tetap di Tailwind CSS v3.4 selama redesign.
- Multi-gudang wajib. Semua perubahan stok lewat `StockService` per gudang, dan `products.stock` tetap total semua gudang. Transfer stok langsung selesai dalam satu langkah.
- Pembelian tanpa PO tetap tersedia. Halaman profil pengguna dihidupkan lagi.
- Modul HR bernama **SDM** di tampilan dan kode: `routes/modules/sdm.php`, `App\Http\Controllers\Sdm\*`, `App\Services\Sdm\*`, izin berawalan `sdm.`. Ini pengecualian dari aturan nama kode berbahasa Inggris. Nama tabel, URL, dan nama route SDM yang sudah ada tidak diubah.
- Nama tabel dan kolom lama yang berbahasa Indonesia tidak di-rename; kode baru memakai bahasa Inggris.
- Sesi kasir memakai tabel `cash_sessions`, karena tabel `shifts` sudah dipakai untuk shift kerja SDM.
- Pekerjaan redesign dilakukan di branch `redesign-tokoku`.

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.3. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This project uses PHPUnit. Create tests with `php artisan make:test --phpunit {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/phpunit` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
## Aturan Enterprise
- Nominal uang: kolom decimal(18,2), kuantitas decimal(18,4). Jangan pakai float. Gunakan helper/Money cast.
- Semua transaksi yang menyentuh stok, kas, atau jurnal wajib dalam DB::transaction dan menggunakan lockForUpdate bila perlu.
- Dokumen bisnis punya status: draft → submitted → approved → posted → cancelled. Dokumen posted tidak boleh diedit/dihapus; koreksi lewat pembatalan/jurnal balik (reversal).
- Jurnal wajib seimbang (total debit = total kredit) dan tidak boleh diposting ke periode yang sudah dikunci.
- Semua tabel transaksi punya company_id/branch_id (scope via global scope), created_by, updated_by, soft deletes.
- Tarif pajak, BPJS, PTKP, TER PPh 21, UMR: SELALU disimpan sebagai data yang bisa diatur di Pengaturan (dengan tanggal berlaku), JANGAN hardcode.
- Proses berat (laporan besar, payroll massal, import, email) lewat Queue job.
- Setiap modul baru wajib: permission seeder, entri di config/menu.php, feature test untuk alur utama.
