<?php

namespace App\Services\Assets;

use App\Enums\DepreciationMethod;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\AssetCategory;

/** Kategori aset. Kategori standar mengikuti kelompok harta berwujud (UU PPh): tarif dan umur bisa diubah. */
class AssetCategoryService
{
    /** [kode, nama, bulan, metode, tarif saldo menurun, akun aset] */
    private const STANDARD = [
        ['KLP1', 'Peralatan & inventaris (kelompok 1)', 48, DepreciationMethod::StraightLine, 50, '1-2100'],
        ['KLP2', 'Kendaraan & mesin (kelompok 2)', 96, DepreciationMethod::StraightLine, 25, '1-2200'],
        ['BGN', 'Bangunan permanen', 240, DepreciationMethod::StraightLine, null, '1-2300'],
    ];

    /** Pasang kategori standar yang belum ada. Mengembalikan jumlah kategori baru. */
    public function installStandard(): int
    {
        $accumulated = Account::query()->where('code', '1-2900')->value('id');
        $expense = Account::query()->where('code', '6-1400')->value('id');
        if ($accumulated === null || $expense === null) {
            throw new AturanBisnisException('Akun Akumulasi Penyusutan (1-2900) dan Beban Penyusutan (6-1400) belum ada. Pasang bagan akun standar dulu.');
        }

        $created = 0;
        foreach (self::STANDARD as [$code, $name, $months, $method, $rate, $assetCode]) {
            $asset = Account::query()->where('code', $assetCode)->value('id');
            if ($asset === null || AssetCategory::withTrashed()->where('code', $code)->exists()) {
                continue;
            }
            AssetCategory::create([
                'code' => $code, 'name' => $name, 'useful_life_months' => $months, 'method' => $method, 'declining_rate' => $rate,
                'asset_account_id' => $asset, 'accumulated_account_id' => $accumulated, 'expense_account_id' => $expense, 'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }

    public function delete(AssetCategory $category): void
    {
        if ($category->assets()->withTrashed()->exists()) {
            throw new AturanBisnisException("Kategori {$category->name} sudah dipakai aset, jadi tidak bisa dihapus. Nonaktifkan saja.");
        }
        $category->delete();
    }
}
