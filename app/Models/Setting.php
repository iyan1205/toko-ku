<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan aplikasi berbentuk key-value.
 *
 * Tidak ada cache statis di sini dengan sengaja: nilai harus selalu segar setelah
 * disimpan, dan cache proses akan bocor antar-tes saat database di-refresh.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /** Ambil satu nilai. String kosong dianggap "belum diisi" dan diganti default. */
    public static function ambil(string $key, ?string $default = null): ?string
    {
        $nilai = static::query()->where('key', $key)->value('value');

        return ($nilai === null || trim((string) $nilai) === '') ? $default : (string) $nilai;
    }

    /** @param array<string, string|null> $pasangan key => nilai */
    public static function simpan(array $pasangan): void
    {
        foreach ($pasangan as $key => $value) {
            static::updateOrCreate(
                ['key' => $key],
                ['value' => ($value === null || trim((string) $value) === '') ? null : (string) $value],
            );
        }
    }

    /** @return array<string, string|null> key => nilai */
    public static function semua(): array
    {
        return static::query()->pluck('value', 'key')->all();
    }
}
