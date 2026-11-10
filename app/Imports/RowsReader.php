<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Pembaca berkas impor: lembar pertama, baris 1 = judul kolom (di-slug, misal "Nama Pelanggan" →
 * nama_pelanggan). Aturan bisnis ada di ImportDefinition.
 */
class RowsReader implements ToCollection, WithCalculatedFormulas, WithHeadingRow
{
    /** @var Collection<int, Collection<string, mixed>> */
    public Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function collection(Collection $rows): void
    {
        // Hanya lembar pertama ("Data"); lembar Petunjuk di templat diabaikan.
        if ($this->rows->isEmpty()) {
            $this->rows = $rows;
        }
    }
}
