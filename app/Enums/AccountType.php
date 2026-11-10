<?php

namespace App\Enums;

/** Kelompok akun di bagan akun (COA). Angka awal kode akun mengikuti urutan ini (1–6). */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Cogs = 'cogs';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Aset',
            self::Liability => 'Liabilitas',
            self::Equity => 'Ekuitas',
            self::Revenue => 'Pendapatan',
            self::Cogs => 'Harga Pokok Penjualan',
            self::Expense => 'Beban',
        };
    }

    /** Saldo normal bawaan. Akun kontra (misal Retur Penjualan) bisa berbeda. */
    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Cogs, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Revenue => NormalBalance::Credit,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Asset => 'info',
            self::Liability => 'warning',
            self::Equity => 'primary',
            self::Revenue => 'success',
            self::Cogs, self::Expense => 'danger',
        };
    }
}
