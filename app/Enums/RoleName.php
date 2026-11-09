<?php

namespace App\Enums;

/**
 * Peran bawaan (dibuat RolePermissionSeeder). Peran tambahan bisa dibuat lewat halaman Peran;
 * peran buatan sendiri tidak ada di enum ini. Hanya Super Admin yang diperlakukan khusus oleh kode.
 */
enum RoleName: string
{
    case SuperAdmin = 'Super Admin';
    case Pemilik = 'Pemilik';
    case Admin = 'Admin';
    case Kasir = 'Kasir';
    case StafGudang = 'Staf Gudang';
    case Purchasing = 'Purchasing';
    case Akuntan = 'Akuntan';
    case Hrd = 'HRD';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function deskripsi(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Akses penuh ke seluruh fitur. Peran sistem: tidak bisa diubah atau dihapus.',
            self::Pemilik => 'Pemilik toko: melihat dan mengelola seluruh bisnis, termasuk laporan dan pengaturan.',
            self::Admin => 'Mengelola operasional harian: produk, stok, pembelian, penjualan, dan pegawai.',
            self::Kasir => 'Melayani transaksi penjualan, melihat produk dan stok, mencetak struk.',
            self::StafGudang => 'Mengelola stok: penyesuaian, opname, transfer, dan penerimaan barang.',
            self::Purchasing => 'Mengelola supplier, purchase order, faktur pembelian, dan penerimaan barang.',
            self::Akuntan => 'Mengelola akuntansi, hutang-piutang, dan laporan keuangan.',
            self::Hrd => 'Mengelola karyawan, absensi, jadwal, cuti, dan penggajian.',
        };
    }
}
