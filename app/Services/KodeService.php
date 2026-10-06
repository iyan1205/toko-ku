<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Pembuat kode berurutan (SUP-0001, CUS-0001, PRD-00001).
 * Panggil di dalam DB::transaction() agar baris terakhir terkunci saat kode dibaca.
 * Kode milik data yang sudah dihapus (soft delete) ikut dihitung supaya tidak pernah dipakai ulang.
 */
class KodeService
{
    /** @param class-string<Model> $modelClass */
    public function berikutnya(string $modelClass, string $kolom, string $awalan, int $lebar = 4): string
    {
        $kolomAman = DB::getQueryGrammar()->wrap($kolom);

        $terakhir = $this->dasar($modelClass)
            ->where($kolom, 'like', $awalan . '%')
            ->orderByRaw("LENGTH({$kolomAman}) desc")
            ->orderByDesc($kolom)
            ->lockForUpdate()
            ->value($kolom);

        $angka = 0;
        if ($terakhir !== null) {
            $sisa = substr($terakhir, strlen($awalan));
            $angka = ctype_digit($sisa) ? (int) $sisa : 0;
        }

        // Pengaman bila ada kode buatan manual yang kebetulan berpola sama.
        do {
            $angka++;
            $kode = $awalan . str_pad((string) $angka, $lebar, '0', STR_PAD_LEFT);
        } while ($this->dasar($modelClass)->where($kolom, $kode)->exists());

        return $kode;
    }

    /** Kode data yang sudah dihapus ikut dihitung; model tanpa soft delete dibaca apa adanya. */
    private function dasar(string $modelClass)
    {
        return in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)
            ? $modelClass::withTrashed()
            : $modelClass::query();
    }
}
