<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Exceptions\AturanBisnisException;
use App\Models\User;
use App\Services\Settings\DatabaseBackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\SiapkanPeran;
use Tests\TestCase;

class BackupTest extends TestCase
{
    use RefreshDatabase, SiapkanPeran;

    private const KONEKSI = 'mysql_uji';

    private User $pemilik;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanPeran();
        $this->pemilik = $this->buatPengguna(RoleName::Pemilik);
        Storage::fake('local');

        // Koneksi MySQL tiruan: tidak pernah benar-benar dihubungi karena mysqldump dipalsukan.
        config(['database.connections.'.self::KONEKSI => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'toko_ku',
            'username' => 'toko', 'password' => 'rah"asia\\123', 'unix_socket' => '',
        ]]);
    }

    /**
     * mysqldump palsu yang menulis isi dump ke --result-file, seperti aslinya.
     *
     * @param  array<int, mixed>  $tangkap  perintah dan isi berkas opsi saat proses berjalan
     */
    private function mysqldumpPalsu(array &$tangkap, string $isi = "-- dump toko_ku\nCREATE TABLE sales;\n"): void
    {
        Process::fake(function (PendingProcess $process) use (&$tangkap, $isi) {
            $perintah = $process->command;
            $opsi = substr(collect($perintah)->first(fn ($a) => str_starts_with($a, '--defaults-extra-file=')), 22);
            $hasil = substr(collect($perintah)->first(fn ($a) => str_starts_with($a, '--result-file=')), 14);

            $tangkap = ['perintah' => $perintah, 'opsi' => file_get_contents($opsi), 'izin' => fileperms($opsi) & 0777];
            file_put_contents($hasil, $isi);

            return Process::result();
        });
    }

    private function berkasBackup(string $nama, string $isi = 'x'): void
    {
        Storage::disk('local')->put(DatabaseBackupService::DIRECTORY.'/'.$nama, $isi);
    }

    public function test_backup_menghasilkan_berkas_gzip_dari_mysqldump(): void
    {
        $this->travelTo('2026-11-09 01:30:00');
        $tangkap = [];
        $this->mysqldumpPalsu($tangkap);

        $nama = app(DatabaseBackupService::class)->create(self::KONEKSI);

        $this->assertSame('tokoku-20261109-013000.sql.gz', $nama);
        $this->assertSame("-- dump toko_ku\nCREATE TABLE sales;\n", gzdecode(Storage::disk('local')->get('backups/'.$nama)));
        $this->assertSame('mysqldump', $tangkap['perintah'][0]);
        $this->assertStringStartsWith('--defaults-extra-file=', $tangkap['perintah'][1], 'Berkas opsi wajib jadi opsi pertama.');
        $this->assertSame('toko_ku', end($tangkap['perintah']));
    }

    public function test_kata_sandi_database_tidak_pernah_ada_di_perintah_dan_berkas_opsi_dihapus(): void
    {
        $tangkap = [];
        $this->mysqldumpPalsu($tangkap);

        app(DatabaseBackupService::class)->create(self::KONEKSI);

        foreach ($tangkap['perintah'] as $argumen) {
            $this->assertStringNotContainsString('asia', $argumen);
        }
        $this->assertStringContainsString('password="rah\"asia\\\\123"', $tangkap['opsi'], 'Tanda kutip dan backslash di-escape.');
        $this->assertSame(0600, $tangkap['izin'], 'Berkas opsi hanya bisa dibaca pemiliknya.');
        $this->assertSame(['backups/'.app(DatabaseBackupService::class)->all()->first()['name']], Storage::disk('local')->allFiles(),
            'Berkas opsi dan dump sementara sudah dihapus.');
    }

    public function test_mysqldump_gagal_menampilkan_pesan_tanpa_meninggalkan_berkas(): void
    {
        Process::fake(fn () => Process::result(errorOutput: "mysqldump: Got error: 1045: Access denied for user 'toko'@'localhost'", exitCode: 2));

        try {
            app(DatabaseBackupService::class)->create(self::KONEKSI);
            $this->fail('Backup seharusnya gagal.');
        } catch (AturanBisnisException $e) {
            $this->assertSame("Backup gagal dibuat: mysqldump: Got error: 1045: Access denied for user 'toko'@'localhost'", $e->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_hanya_backup_terbaru_yang_disimpan(): void
    {
        for ($hari = 1; $hari <= DatabaseBackupService::KEEP; $hari++) {
            $this->berkasBackup(sprintf('tokoku-202610%02d-013000.sql.gz', $hari));
        }
        $this->travelTo('2026-11-09 01:30:00');
        $tangkap = [];
        $this->mysqldumpPalsu($tangkap);

        app(DatabaseBackupService::class)->create(self::KONEKSI);

        $nama = app(DatabaseBackupService::class)->all()->pluck('name');
        $this->assertCount(DatabaseBackupService::KEEP, $nama);
        $this->assertSame('tokoku-20261109-013000.sql.gz', $nama->first());
        $this->assertNotContains('tokoku-20261001-013000.sql.gz', $nama, 'Backup tertua dihapus.');
    }

    // ---------------- Halaman ----------------

    public function test_daftar_backup_dan_unduh(): void
    {
        $this->berkasBackup('tokoku-20261108-013000.sql.gz', 'isi-backup');

        $this->actingAs($this->pemilik)
            ->get(route('settings.backups.index'))
            ->assertOk()
            ->assertSee('tokoku-20261108-013000.sql.gz');

        $this->actingAs($this->pemilik)
            ->get(route('settings.backups.download', 'tokoku-20261108-013000.sql.gz'))
            ->assertOk()
            ->assertDownload('tokoku-20261108-013000.sql.gz');

        $log = Activity::query()->where('event', 'backup_downloaded')->sole();
        $this->assertTrue($log->causer->is($this->pemilik));
    }

    public function test_berkas_selain_backup_tidak_bisa_diunduh(): void
    {
        Storage::disk('local')->put('backups/.env', 'APP_KEY=rahasia');
        Storage::disk('local')->put('backups/tokoku-20261108-013000.sql.gz.php', 'x');

        foreach (['.env', 'tokoku-20261108-013000.sql.gz.php', 'tokoku-20261108-013000.sql.gz'] as $nama) {
            $this->actingAs($this->pemilik)->get(route('settings.backups.download', $nama))->assertNotFound();
        }
    }

    public function test_database_selain_mysql_tidak_bisa_dibackup_dari_halaman(): void
    {
        $this->actingAs($this->pemilik)
            ->get(route('settings.backups.index'))
            ->assertSee('Backup dari aplikasi hanya tersedia untuk database MySQL/MariaDB.')
            ->assertDontSee('Buat backup sekarang');

        $this->actingAs($this->pemilik)
            ->post(route('settings.backups.store'))
            ->assertSessionHas('error', 'Backup hanya tersedia untuk database MySQL/MariaDB.');
    }

    public function test_admin_tanpa_izin_tidak_bisa_membuka_membuat_atau_mengunduh(): void
    {
        $admin = $this->buatPengguna(RoleName::Admin);
        $this->berkasBackup('tokoku-20261108-013000.sql.gz');

        $this->actingAs($admin)->get(route('settings.backups.index'))->assertForbidden();
        $this->actingAs($admin)->post(route('settings.backups.store'))->assertForbidden();
        $this->actingAs($admin)->get(route('settings.backups.download', 'tokoku-20261108-013000.sql.gz'))->assertForbidden();
    }

    // ---------------- Perintah terjadwal ----------------

    public function test_perintah_backup_mencatat_log_saat_berhasil(): void
    {
        $this->mock(DatabaseBackupService::class)->shouldReceive('create')->once()->andReturn('tokoku-20261109-013000.sql.gz');

        $this->artisan('backup:database')
            ->expectsOutput('Backup tersimpan: tokoku-20261109-013000.sql.gz')
            ->assertSuccessful();

        $this->assertSame('jadwal otomatis', Activity::query()->where('event', 'backup_created')->sole()->properties['sumber']);
    }

    public function test_perintah_backup_gagal_dengan_kode_keluar_bukan_nol(): void
    {
        $this->artisan('backup:database')
            ->expectsOutput('Backup hanya tersedia untuk database MySQL/MariaDB.')
            ->assertFailed();
    }
}
