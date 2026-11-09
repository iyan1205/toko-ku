<?php

namespace App\Enums;

use DateTimeInterface;

/** Kapan nomor urut dokumen kembali ke 1. */
enum NumberingReset: string
{
    case Daily = 'daily';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Never = 'never';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Setiap hari',
            self::Monthly => 'Setiap bulan',
            self::Yearly => 'Setiap tahun',
            self::Never => 'Tidak pernah',
        };
    }

    /** Kunci periode di tabel document_sequences. */
    public function periodKey(DateTimeInterface $date): string
    {
        return match ($this) {
            self::Daily => $date->format('Ymd'),
            self::Monthly => $date->format('Ym'),
            self::Yearly => $date->format('Y'),
            self::Never => 'all',
        };
    }

    /**
     * Penanda tanggal yang wajib ada di pola agar nomor tidak berulang setelah reset.
     * Setiap elemen adalah daftar alternatif: salah satunya harus ada.
     *
     * @return list<list<string>>
     */
    public function requiredTokens(): array
    {
        return match ($this) {
            self::Daily => [['{DD}'], ['{MM}'], ['{YYYY}', '{YY}']],
            self::Monthly => [['{MM}'], ['{YYYY}', '{YY}']],
            self::Yearly => [['{YYYY}', '{YY}']],
            self::Never => [],
        };
    }
}
