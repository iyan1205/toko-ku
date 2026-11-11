<?php

namespace App\Exports\Accounting;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Templat rekening koran untuk rekonsiliasi bank (judul kolom yang dikenali + contoh). */
class BankStatementTemplateExport implements Export, FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    public function headings(): array
    {
        return ['Tanggal', 'Keterangan', 'Referensi', 'Masuk', 'Keluar', 'Saldo'];
    }

    public function array(): array
    {
        return [
            ['01/11/2026', 'Setoran tunai', 'STR-001', 1500000, null, 11500000],
            ['02/11/2026', 'Transfer ke CV Ayam Segar', 'TRF-7781', null, 750000, 10750000],
            ['30/11/2026', 'Biaya administrasi', null, null, 15000, 10735000],
        ];
    }
}
