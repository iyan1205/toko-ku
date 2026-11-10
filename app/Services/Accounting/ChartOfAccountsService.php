<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Bagan akun standar toko retail Indonesia (mengacu pola SAK EMKM). Dipasang oleh
 * ChartOfAccountsSeeder atau tombol "Pasang COA standar" di halaman Bagan Akun.
 *
 * Aman dijalankan ulang: akun yang kodenya sudah ada tidak diubah (nama/deskripsi dari pemilik
 * tetap), hanya akun yang belum ada yang ditambahkan. Akun yang dipakai jurnal otomatis
 * (config/accounting.php) ditandai akun sistem.
 */
class ChartOfAccountsService
{
    /**
     * [kode, nama, tipe, kode induk, akun induk?, saldo normal khusus (akun kontra), deskripsi]
     *
     * @var list<array{0: string, 1: string, 2: AccountType, 3: ?string, 4: bool, 5?: ?NormalBalance, 6?: string}>
     */
    private const STANDARD = [
        ['1-0000', 'Aset', AccountType::Asset, null, true],
        ['1-1000', 'Aset Lancar', AccountType::Asset, '1-0000', true],
        ['1-1100', 'Kas', AccountType::Asset, '1-1000', false, null, 'Uang tunai di laci kasir dan brankas.'],
        ['1-1200', 'Bank', AccountType::Asset, '1-1000', false, null, 'Rekening bank, termasuk dana masuk dari transfer, kartu debit/kredit, QRIS, dan e-wallet.'],
        ['1-1300', 'Piutang Usaha', AccountType::Asset, '1-1000', false, null, 'Tagihan ke pelanggan dari penjualan piutang.'],
        ['1-1350', 'Piutang Lain-lain', AccountType::Asset, '1-1000', false, null, 'Termasuk uang retur yang harus dikembalikan supplier.'],
        ['1-1400', 'Persediaan Barang Dagang', AccountType::Asset, '1-1000', false],
        ['1-1500', 'PPN Masukan', AccountType::Asset, '1-1000', false, null, 'Pajak yang dibayar saat pembelian.'],
        ['1-1600', 'Biaya Dibayar di Muka', AccountType::Asset, '1-1000', false, null, 'Misal sewa yang dibayar untuk beberapa bulan.'],
        ['1-2000', 'Aset Tetap', AccountType::Asset, '1-0000', true],
        ['1-2100', 'Peralatan Toko', AccountType::Asset, '1-2000', false, null, 'Etalase, kulkas, mesin kasir, printer, dan peralatan lain.'],
        ['1-2200', 'Kendaraan', AccountType::Asset, '1-2000', false],
        ['1-2900', 'Akumulasi Penyusutan', AccountType::Asset, '1-2000', false, NormalBalance::Credit, 'Akun kontra aset tetap.'],

        ['2-0000', 'Liabilitas', AccountType::Liability, null, true],
        ['2-1000', 'Liabilitas Jangka Pendek', AccountType::Liability, '2-0000', true],
        ['2-1100', 'Hutang Usaha', AccountType::Liability, '2-1000', false, null, 'Hutang ke supplier dari pembelian.'],
        ['2-1200', 'PPN Keluaran', AccountType::Liability, '2-1000', false, null, 'Pajak yang dipungut dari pelanggan saat penjualan.'],
        ['2-1300', 'Hutang Gaji', AccountType::Liability, '2-1000', false],
        ['2-1400', 'Hutang Pajak Lainnya', AccountType::Liability, '2-1000', false],
        ['2-1900', 'Hutang Lain-lain', AccountType::Liability, '2-1000', false],
        ['2-2000', 'Liabilitas Jangka Panjang', AccountType::Liability, '2-0000', true],
        ['2-2100', 'Hutang Bank', AccountType::Liability, '2-2000', false],

        ['3-0000', 'Ekuitas', AccountType::Equity, null, true],
        ['3-1000', 'Modal Pemilik', AccountType::Equity, '3-0000', false],
        ['3-2000', 'Prive Pemilik', AccountType::Equity, '3-0000', false, NormalBalance::Debit, 'Pengambilan uang toko untuk keperluan pribadi pemilik.'],
        ['3-3000', 'Laba Ditahan', AccountType::Equity, '3-0000', false],

        ['4-0000', 'Pendapatan', AccountType::Revenue, null, true],
        ['4-1000', 'Penjualan', AccountType::Revenue, '4-0000', false, null, 'Penjualan bersih setelah diskon, tanpa pajak.'],
        ['4-1100', 'Retur Penjualan', AccountType::Revenue, '4-0000', false, NormalBalance::Debit, 'Akun kontra pendapatan.'],
        ['4-2000', 'Pendapatan Lain-lain', AccountType::Revenue, '4-0000', false],

        ['5-0000', 'Harga Pokok Penjualan', AccountType::Cogs, null, true],
        ['5-1000', 'Harga Pokok Penjualan', AccountType::Cogs, '5-0000', false, null, 'Harga modal barang yang terjual.'],
        ['5-2000', 'Selisih Persediaan', AccountType::Cogs, '5-0000', false, null, 'Selisih hasil stok opname, barang rusak, dan hilang.'],

        ['6-0000', 'Beban Operasional', AccountType::Expense, null, true],
        ['6-1000', 'Beban Gaji & Upah', AccountType::Expense, '6-0000', false],
        ['6-1100', 'Beban Sewa Tempat', AccountType::Expense, '6-0000', false],
        ['6-1200', 'Beban Listrik, Air & Internet', AccountType::Expense, '6-0000', false],
        ['6-1300', 'Beban Perlengkapan Toko', AccountType::Expense, '6-0000', false, null, 'Kantong plastik, kertas struk, alat kebersihan.'],
        ['6-1400', 'Beban Penyusutan', AccountType::Expense, '6-0000', false],
        ['6-1500', 'Beban Transportasi & Pengiriman', AccountType::Expense, '6-0000', false],
        ['6-1600', 'Beban Administrasi Bank', AccountType::Expense, '6-0000', false, null, 'Biaya admin, biaya transfer, dan potongan MDR QRIS/EDC.'],
        ['6-1700', 'Beban Pemasaran', AccountType::Expense, '6-0000', false],
        ['6-1900', 'Beban Lain-lain', AccountType::Expense, '6-0000', false],
    ];

    /** Pasang akun standar yang belum ada. Mengembalikan jumlah akun yang ditambahkan. */
    public function install(): int
    {
        return DB::transaction(function () {
            $existing = Account::withTrashed()->pluck('id', 'code')->all();
            $created = 0;

            foreach (self::STANDARD as $row) {
                [$code, $name, $type, $parent, $header] = $row;
                if (isset($existing[$code])) {
                    continue;
                }

                $account = new Account([
                    'code' => $code,
                    'name' => $name,
                    'type' => $type,
                    'normal_balance' => $row[5] ?? $type->normalBalance(),
                    'parent_id' => $parent ? ($existing[$parent] ?? null) : null,
                    'is_header' => $header,
                    'is_active' => true,
                    'description' => $row[6] ?? null,
                ]);
                $account->save();
                $existing[$code] = $account->id;
                $created++;
            }

            $this->markSystemAccounts();

            return $created;
        });
    }

    /** Tandai akun yang dipakai jurnal otomatis sebagai akun sistem. */
    public function markSystemAccounts(): void
    {
        Account::withTrashed()->whereIn('code', array_values(config('accounting.accounts')))->update(['is_system' => true]);
    }

    public function isInstalled(): bool
    {
        return Account::query()->exists();
    }
}
