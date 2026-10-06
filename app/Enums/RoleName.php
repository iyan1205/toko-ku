<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'Super Admin';
    case Admin = 'Admin';
    case Kasir = 'Kasir';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function deskripsi(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Akses penuh ke seluruh fitur, termasuk pengguna dan pengaturan.',
            self::Admin => 'Mengelola produk, stok, pembelian, supplier, pelanggan, dan laporan.',
            self::Kasir => 'Melayani transaksi penjualan, melihat produk dan stok, mencetak struk.',
        };
    }
}
