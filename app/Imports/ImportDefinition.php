<?php

namespace App\Imports;

use App\Models\User;

/**
 * Definisi satu jenis impor Excel/CSV (kerangka impor Fase 8). Didaftarkan di config/imports.php.
 *
 *   columns()  : kolom templat; kunci = judul kolom yang di-slug (misal "Nama" → nama)
 *   prepare()  : muat data pembanding sekali per impor (misal kode yang sudah ada)
 *   validate() : periksa satu baris → [data bersih, daftar kesalahan]; juga cek ganda di dalam berkas
 *   persist()  : simpan satu baris yang valid → 'created' | 'updated'
 *
 * ImportService membaca berkas, memanggil prepare() + validate() untuk pratinjau, lalu
 * prepare() + validate() + persist() lagi saat diproses (data bisa berubah di antaranya).
 */
abstract class ImportDefinition
{
    /** Kunci di config/imports.php (nama route imports.{kunci}.*). */
    abstract public function key(): string;

    /** "Pelanggan". */
    abstract public function title(): string;

    /** Route daftar data yang diimpor, untuk tombol kembali. */
    abstract public function indexRoute(): string;

    /** @return array<string, array{label: string, required?: bool, hint?: string, example?: string, text?: bool}> */
    abstract public function columns(): array;

    /**
     * @param  array<string, mixed>  $row  nilai mentah per kunci kolom (sudah di-trim)
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    abstract public function validate(array $row, int $line): array;

    /** @param array<string, mixed> $data */
    abstract public function persist(array $data, User $user): string;

    /** Penjelasan cara baris dicocokkan dengan data yang ada. */
    public function matchingRule(): string
    {
        return 'Setiap baris menjadi data baru.';
    }

    public function maxRows(): int
    {
        return 2000;
    }

    public function prepare(): void {}

    /** @return list<string> kunci kolom wajib */
    public function requiredColumns(): array
    {
        return array_keys(array_filter($this->columns(), fn (array $c) => $c['required'] ?? false));
    }

    /** Nilai teks: angka dari Excel (misal 81234 untuk HP) dikembalikan menjadi teks tanpa ".0". */
    protected function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = number_format($value, 0, '', '');
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** Nomor HP: angka 0 di depan yang hilang karena kolom Excel berformat angka dikembalikan. */
    protected function phone(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value !== null && preg_match('/^8\d{7,12}$/', $value) ? '0'.$value : $value;
    }
}
