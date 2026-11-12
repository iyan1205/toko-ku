<?php

namespace App\Enums;

/**
 * Status PTKP karyawan (TK = tidak kawin, K = kawin, angka = jumlah tanggungan, maks 3).
 * Nilai rupiah PTKP tiap status diatur sebagai data berlaku-per-tanggal di penggajian (Fase 14),
 * bukan di sini.
 */
enum PtkpStatus: string
{
    case TK0 = 'TK0';
    case TK1 = 'TK1';
    case TK2 = 'TK2';
    case TK3 = 'TK3';
    case K0 = 'K0';
    case K1 = 'K1';
    case K2 = 'K2';
    case K3 = 'K3';

    public function label(): string
    {
        return substr($this->value, 0, -1).'/'.substr($this->value, -1);
    }
}
