<?php

namespace App\Services\Settings;

use App\Exceptions\AturanBisnisException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cadangan database dengan `mysqldump` (MySQL/MariaDB), dikompres gzip, disimpan di
 * storage/app/private/backups (tidak bisa diakses dari web). Dipakai halaman
 * Pengaturan → Backup dan perintah `php artisan backup:database` (dijadwalkan harian).
 *
 * Kredensial database dibaca dari config dan diberikan ke mysqldump lewat berkas opsi
 * sementara (izin 0600, langsung dihapus), jadi kata sandi tidak muncul di daftar proses
 * server, di log, maupun di layar.
 */
class DatabaseBackupService
{
    public const DIRECTORY = 'backups';

    /** Jumlah berkas cadangan yang disimpan; yang lebih lama dihapus otomatis. */
    public const KEEP = 14;

    private const NAME_PATTERN = '/^tokoku-\d{8}-\d{6}\.sql\.gz$/';

    /**
     * Daftar cadangan, terbaru dulu.
     *
     * @return Collection<int, array{name: string, size: int, created_at: CarbonImmutable}>
     */
    public function all(): Collection
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIRECTORY))
            ->map(fn (string $path) => basename($path))
            ->filter(fn (string $name) => $this->isBackupName($name))
            ->sortDesc()
            ->values()
            ->map(fn (string $name) => [
                'name' => $name,
                'size' => $disk->size(self::DIRECTORY.'/'.$name),
                'created_at' => CarbonImmutable::createFromTimestamp($disk->lastModified(self::DIRECTORY.'/'.$name))
                    ->setTimezone(config('app.timezone')),
            ]);
    }

    /** Nama berkas cadangan yang sah (mencegah path traversal saat mengunduh). */
    public function isBackupName(string $name): bool
    {
        return preg_match(self::NAME_PATTERN, $name) === 1;
    }

    public function path(string $name): string
    {
        return self::DIRECTORY.'/'.$name;
    }

    /**
     * Buat cadangan baru dan kembalikan nama berkasnya.
     *
     * @throws AturanBisnisException bila database bukan MySQL/MariaDB atau mysqldump gagal
     */
    public function create(?string $connection = null): string
    {
        $connection ??= (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            throw new AturanBisnisException('Backup hanya tersedia untuk database MySQL/MariaDB.');
        }

        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIRECTORY);
        $directory = $disk->path(self::DIRECTORY);

        $name = 'tokoku-'.now()->format('Ymd-His').'.sql.gz';
        if ($disk->exists($this->path($name))) {
            throw new AturanBisnisException('Backup baru saja dibuat. Tunggu beberapa detik lalu coba lagi.');
        }

        $token = Str::random(20);
        $optionFile = "{$directory}/.{$token}.cnf";
        $dumpFile = "{$directory}/.{$token}.sql";

        try {
            $this->writeOptionFile($optionFile, $config);

            $result = Process::timeout(600)->run([
                'mysqldump',
                "--defaults-extra-file={$optionFile}", // harus opsi pertama
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--routines',
                '--triggers',
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                "--result-file={$dumpFile}",
                (string) $config['database'],
            ]);

            if ($result->failed() || ! is_file($dumpFile) || filesize($dumpFile) === 0) {
                Log::error('Backup database gagal', ['exit' => $result->exitCode(), 'error' => trim($result->errorOutput())]);

                throw new AturanBisnisException('Backup gagal dibuat: '.(Str::limit(trim($result->errorOutput()), 200) ?: 'mysqldump tidak menghasilkan berkas.'));
            }

            $this->compress($dumpFile, "{$directory}/{$name}");
        } finally {
            @unlink($optionFile);
            @unlink($dumpFile);
        }

        $this->prune();

        return $name;
    }

    /** Hapus cadangan lama, sisakan KEEP berkas terbaru. */
    public function prune(): void
    {
        $this->all()->slice(self::KEEP)
            ->each(fn (array $backup) => Storage::disk('local')->delete($this->path($backup['name'])));
    }

    /** @param array<string, mixed> $config */
    private function writeOptionFile(string $path, array $config): void
    {
        $lines = ['[client]', 'user='.$this->quote((string) ($config['username'] ?? '')), 'password='.$this->quote((string) ($config['password'] ?? ''))];

        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket='.$this->quote((string) $config['unix_socket']);
        } else {
            $lines[] = 'host='.$this->quote((string) ($config['host'] ?? '127.0.0.1'));
            $lines[] = 'port='.(int) ($config['port'] ?? 3306);
        }

        // Buat kosong dulu dengan izin 0600, baru isi kredensialnya.
        touch($path);
        chmod($path, 0600);
        file_put_contents($path, implode("\n", $lines)."\n");
    }

    /** Nilai berkas opsi MySQL dalam tanda kutip, dengan \ dan " di-escape. */
    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '\\"').'"';
    }

    private function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 512));
        }

        fclose($in);
        gzclose($out);
    }
}
