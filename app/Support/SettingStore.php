<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Sumber nilai tabel `settings` untuk helper setting() dan model Setting.
 *
 * Semua pasangan key-value dibaca sekali lalu disimpan di cache aplikasi sampai ada
 * perubahan. Di dalam satu request nilainya juga diingat di objek ini (didaftarkan
 * scoped di AppServiceProvider), jadi memanggil setting() berkali-kali tidak menambah query.
 *
 * Cache dihapus setiap kali ada yang disimpan, dan sekali lagi setelah transaksi database
 * selesai, supaya request lain tidak sempat menyimpan nilai lama ke cache.
 */
final class SettingStore
{
    public const CACHE_KEY = 'settings.all';

    /** @var array<string, string|null>|null */
    private ?array $values = null;

    /** @return array<string, string|null> key => nilai */
    public function all(): array
    {
        return $this->values ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()->pluck('value', 'key')->all(),
        );
    }

    /** Satu nilai. Belum ada atau string kosong dianggap "belum diisi" sehingga memakai $default. */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        return ($value === null || trim((string) $value) === '') ? $default : (string) $value;
    }

    /**
     * Simpan beberapa nilai sekaligus. Nilai kosong disimpan sebagai null (kembali ke bawaan).
     *
     * @param  array<string, string|int|bool|null>  $pairs
     */
    public function set(array $pairs): void
    {
        DB::transaction(function () use ($pairs) {
            foreach ($pairs as $key => $value) {
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => ($value === null || trim((string) $value) === '') ? null : (string) $value],
                );
            }
        });

        $this->flush();
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);

        // Bila dipanggil di dalam transaksi, hapus lagi setelah commit.
        DB::afterCommit(function () {
            $this->values = null;
            Cache::forget(self::CACHE_KEY);
        });
    }
}
