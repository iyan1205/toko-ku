<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Services\CashBank\CashBankAccountService;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bagan akun standar toko retail Indonesia (mengacu pola SAK EMKM). Dipasang oleh
 * ChartOfAccountsSeeder atau tombol "Pasang COA standar" di halaman Bagan Akun.
 *
 * Aman dijalankan ulang: akun yang kodenya sudah ada tidak diubah (nama/deskripsi dari pemilik
 * tetap), hanya akun yang belum ada yang ditambahkan. Akun yang dipetakan untuk jurnal otomatis
 * (AccountMappingService) ditandai akun sistem.
 */
class ChartOfAccountsService
{
    /** Akun kas/bank di COA standar (bisa dipilih sebagai akun metode pembayaran). */
    private const CASH_BANK = ['1-1100', '1-1200'];

    /**
     * [kode, nama, tipe, kode induk, akun induk?, saldo normal khusus (akun kontra), deskripsi]
     *
     * @var list<array{0: string, 1: string, 2: AccountType, 3: ?string, 4: bool, 5?: ?NormalBalance, 6?: string}>
     */
    public const STANDARD = [
        ['1-0000', 'Aset', AccountType::Asset, null, true],
        ['1-1000', 'Aset Lancar', AccountType::Asset, '1-0000', true],
        ['1-1100', 'Kas', AccountType::Asset, '1-1000', false, null, 'Uang tunai di laci kasir dan brankas.'],
        ['1-1150', 'Cek & Giro Diterima', AccountType::Asset, '1-1000', false, null, 'Giro/cek mundur dari pelanggan yang belum cair.'],
        ['1-1200', 'Bank', AccountType::Asset, '1-1000', false, null, 'Rekening bank, termasuk dana masuk dari transfer, kartu debit/kredit, QRIS, dan e-wallet.'],
        ['1-1300', 'Piutang Usaha', AccountType::Asset, '1-1000', false, null, 'Tagihan ke pelanggan dari penjualan piutang.'],
        ['1-1350', 'Piutang Lain-lain', AccountType::Asset, '1-1000', false, null, 'Termasuk uang retur yang harus dikembalikan supplier.'],
        ['1-1360', 'Uang Muka Pembelian', AccountType::Asset, '1-1000', false, null, 'Uang yang sudah dibayar ke supplier sebelum fakturnya ada.'],
        ['1-1400', 'Persediaan Barang Dagang', AccountType::Asset, '1-1000', false],
        ['1-1500', 'PPN Masukan', AccountType::Asset, '1-1000', false, null, 'Pajak yang dibayar saat pembelian.'],
        ['1-1600', 'Biaya Dibayar di Muka', AccountType::Asset, '1-1000', false, null, 'Misal sewa yang dibayar untuk beberapa bulan.'],
        ['1-1700', 'Rekening Antar Cabang', AccountType::Asset, '1-1000', false, null, 'Penampung nilai barang yang ditransfer antar cabang. Bila semua cabang dijumlahkan, saldonya nol.'],
        ['1-2000', 'Aset Tetap', AccountType::Asset, '1-0000', true],
        ['1-2100', 'Peralatan Toko', AccountType::Asset, '1-2000', false, null, 'Etalase, kulkas, mesin kasir, printer, dan peralatan lain.'],
        ['1-2200', 'Kendaraan', AccountType::Asset, '1-2000', false],
        ['1-2300', 'Bangunan', AccountType::Asset, '1-2000', false, null, 'Bangunan toko, gudang, atau renovasi permanen.'],
        ['1-2900', 'Akumulasi Penyusutan', AccountType::Asset, '1-2000', false, NormalBalance::Credit, 'Akun kontra aset tetap.'],

        ['2-0000', 'Liabilitas', AccountType::Liability, null, true],
        ['2-1000', 'Liabilitas Jangka Pendek', AccountType::Liability, '2-0000', true],
        ['2-1100', 'Hutang Usaha', AccountType::Liability, '2-1000', false, null, 'Hutang ke supplier dari pembelian.'],
        ['2-1150', 'Hutang Giro', AccountType::Liability, '2-1000', false, null, 'Giro/cek mundur yang sudah diberikan ke supplier tetapi belum cair.'],
        ['2-1200', 'PPN Keluaran', AccountType::Liability, '2-1000', false, null, 'Pajak yang dipungut dari pelanggan saat penjualan.'],
        ['2-1300', 'Hutang Gaji', AccountType::Liability, '2-1000', false],
        ['2-1400', 'Hutang Pajak Lainnya', AccountType::Liability, '2-1000', false],
        ['2-1410', 'Hutang PPh 23', AccountType::Liability, '2-1000', false, null, 'PPh 23 yang dipotong dari pembayaran jasa ke supplier dan wajib disetor.'],
        ['2-1420', 'Hutang PPh 4(2)', AccountType::Liability, '2-1000', false, null, 'PPh final 4 ayat 2 (misal sewa bangunan) yang dipotong dan wajib disetor.'],
        ['2-1500', 'Uang Muka Pelanggan', AccountType::Liability, '2-1000', false, null, 'Uang dari pelanggan yang belum dipakai melunasi faktur.'],
        ['2-1900', 'Hutang Lain-lain', AccountType::Liability, '2-1000', false],
        ['2-2000', 'Liabilitas Jangka Panjang', AccountType::Liability, '2-0000', true],
        ['2-2100', 'Hutang Bank', AccountType::Liability, '2-2000', false],

        ['3-0000', 'Ekuitas', AccountType::Equity, null, true],
        ['3-1000', 'Modal Pemilik', AccountType::Equity, '3-0000', false],
        ['3-2000', 'Prive Pemilik', AccountType::Equity, '3-0000', false, NormalBalance::Debit, 'Pengambilan uang toko untuk keperluan pribadi pemilik.'],
        ['3-3000', 'Laba Ditahan', AccountType::Equity, '3-0000', false],
        ['3-9000', 'Ekuitas Saldo Awal', AccountType::Equity, '3-0000', false, null, 'Penyeimbang saat saldo awal dimasukkan. Setelah saldo awal lengkap, pindahkan ke Modal Pemilik dengan jurnal manual.'],

        ['4-0000', 'Pendapatan', AccountType::Revenue, null, true],
        ['4-1000', 'Penjualan', AccountType::Revenue, '4-0000', false, null, 'Penjualan bersih setelah diskon, tanpa pajak.'],
        ['4-1100', 'Retur Penjualan', AccountType::Revenue, '4-0000', false, NormalBalance::Debit, 'Akun kontra pendapatan.'],
        ['4-1200', 'Diskon Penjualan', AccountType::Revenue, '4-0000', false, NormalBalance::Debit, 'Akun kontra pendapatan: potongan harga per barang dan per transaksi di kasir.'],
        ['4-1300', 'Potongan Penjualan', AccountType::Revenue, '4-0000', false, NormalBalance::Debit, 'Akun kontra pendapatan: potongan setelah transaksi lewat nota kredit.'],
        ['4-2000', 'Pendapatan Lain-lain', AccountType::Revenue, '4-0000', false],
        ['4-2100', 'Laba Pelepasan Aset Tetap', AccountType::Revenue, '4-0000', false, null, 'Selisih harga jual di atas nilai buku aset yang dijual.'],

        ['5-0000', 'Harga Pokok Penjualan', AccountType::Cogs, null, true],
        ['5-1000', 'Harga Pokok Penjualan', AccountType::Cogs, '5-0000', false, null, 'Harga modal barang yang terjual.'],
        ['5-1500', 'Potongan Pembelian', AccountType::Cogs, '5-0000', false, NormalBalance::Credit, 'Potongan dari supplier setelah faktur lewat nota debit.'],
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
        ['6-1950', 'Rugi Pelepasan Aset Tetap', AccountType::Expense, '6-0000', false, null, 'Nilai buku aset yang dijual di bawah harga atau dihapus.'],
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
                    'is_cash_bank' => in_array($code, self::CASH_BANK, true),
                    'is_active' => true,
                    'description' => $row[6] ?? null,
                ]);
                $account->save();
                $existing[$code] = $account->id;
                $created++;
            }

            $this->markSystemAccounts();
            // Kas dan Bank langsung bisa dipakai di Kas & Bank, pelunasan, dan rekonsiliasi.
            app(CashBankAccountService::class)->linkCashBankAccounts();

            return $created;
        });
    }

    /** Tandai akun yang dipetakan untuk jurnal otomatis sebagai akun sistem. */
    public function markSystemAccounts(): void
    {
        app(AccountMappingService::class)->syncSystemFlags();
    }

    public function isInstalled(): bool
    {
        return Account::query()->exists();
    }

    /** @return list<string> kode akun standar yang belum ada (misal akun baru di versi aplikasi berikutnya) */
    public function missingCodes(): array
    {
        $existing = Account::withTrashed()->pluck('code')->all();

        return array_values(array_diff(array_column(self::STANDARD, 0), $existing));
    }

    /**
     * Susun akun menjadi pohon (urutan tampil dari atas ke bawah). Saldo akun induk = jumlah saldo
     * semua akun di bawahnya, mengikuti saldo normal akun induk itu. $visible membatasi baris yang
     * ditampilkan (hasil filter) beserta akun induknya; null = semua.
     *
     * @param  Collection<int, Account>  $accounts  dengan debit_total/credit_total (AccountsExport)
     * @param  list<int>|null  $visible
     * @return list<array{account: Account, depth: int, ancestors: list<int>, has_children: bool, balance: ?string}>
     */
    public function tree(Collection $accounts, ?array $visible = null): array
    {
        $byParent = $accounts->groupBy(fn (Account $a) => (int) $a->parent_id);
        $byId = $accounts->keyBy('id');

        // Saldo bersih (debit - kredit, sen) per akun termasuk semua turunannya.
        $net = [];
        $sum = function (Account $account) use (&$sum, &$net, $byParent): int {
            $own = Uang::sen($account->getAttribute('debit_total') ?? 0) - Uang::sen($account->getAttribute('credit_total') ?? 0);
            foreach ($byParent->get($account->id, collect()) as $child) {
                $own += $sum($child);
            }

            return $net[$account->id] = $own;
        };

        // Akun yang induknya tidak ada (terhapus) diperlakukan sebagai akun teratas.
        $roots = $accounts->filter(fn (Account $a) => $a->parent_id === null || ! $byId->has($a->parent_id))->sortBy('code');
        foreach ($roots as $root) {
            $sum($root);
        }

        $show = null;
        if ($visible !== null) {
            $show = [];
            foreach ($visible as $id) {
                for ($node = $byId->get($id); $node !== null && ! isset($show[$node->id]); $node = $byId->get((int) $node->parent_id)) {
                    $show[$node->id] = true;
                }
            }
        }

        $rows = [];
        $walk = function (Account $account, int $depth, array $ancestors) use (&$walk, &$rows, $byParent, $net, $show): void {
            if ($show !== null && ! isset($show[$account->id])) {
                return;
            }
            $children = $byParent->get($account->id, collect())->sortBy('code');
            $saldo = $net[$account->id] ?? 0;
            $rows[] = [
                'account' => $account,
                'depth' => $depth,
                'ancestors' => $ancestors,
                'has_children' => $children->isNotEmpty(),
                'balance' => Uang::keDesimal($account->normal_balance === NormalBalance::Debit ? $saldo : -$saldo),
            ];
            foreach ($children as $child) {
                $walk($child, $depth + 1, [...$ancestors, $account->id]);
            }
        };

        foreach ($roots as $root) {
            $walk($root, 0, []);
        }

        return $rows;
    }
}
