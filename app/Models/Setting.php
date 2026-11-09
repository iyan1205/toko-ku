<?php

namespace App\Models;

use App\Support\SettingStore;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan aplikasi berbentuk key-value. Kode baru cukup memakai helper setting().
 *
 * Nilai dibaca lewat SettingStore: di-cache sampai ada perubahan. Cache aplikasi saat test
 * memakai driver `array` (phpunit.xml), yang dibuat baru untuk setiap test, jadi tidak bocor
 * antar-test saat database di-refresh.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        // Perubahan lewat jalur mana pun (bukan hanya simpan()) tetap menghapus cache.
        static::saved(fn () => app(SettingStore::class)->flush());
        static::deleted(fn () => app(SettingStore::class)->flush());
    }

    /** Ambil satu nilai. String kosong dianggap "belum diisi" dan diganti default. */
    public static function ambil(string $key, ?string $default = null): ?string
    {
        return app(SettingStore::class)->get($key, $default);
    }

    /** @param array<string, string|null> $pasangan key => nilai */
    public static function simpan(array $pasangan): void
    {
        app(SettingStore::class)->set($pasangan);
    }

    /** @return array<string, string|null> key => nilai */
    public static function semua(): array
    {
        return app(SettingStore::class)->all();
    }
}
