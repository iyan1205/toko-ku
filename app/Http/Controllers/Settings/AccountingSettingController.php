<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccountingSettingsRequest;
use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Pengaturan → Akuntansi: nyala/mati jurnal otomatis dan akun yang dipakainya. */
class AccountingSettingController extends Controller
{
    /** Nama peran setiap akun di config/accounting.php. */
    private const ROLES = [
        'cash' => 'Kas (pembayaran tunai)',
        'bank' => 'Bank (transfer, debit, kartu kredit, QRIS, e-wallet)',
        'receivable' => 'Piutang pelanggan',
        'other_receivable' => 'Uang retur dari supplier',
        'inventory' => 'Persediaan',
        'vat_in' => 'PPN masukan (pembelian)',
        'payable' => 'Hutang supplier',
        'vat_out' => 'PPN keluaran (penjualan)',
        'sales' => 'Penjualan',
        'sales_return' => 'Retur penjualan',
        'cogs' => 'Harga pokok penjualan',
    ];

    public function edit(): View
    {
        $codes = config('accounting.accounts');
        $accounts = Account::query()->whereIn('code', array_values($codes))->get()->keyBy('code');

        return view('settings.accounting', [
            'enabled' => setting('accounting.auto_journal', '1') === '1',
            'installed' => Account::query()->exists(),
            'mapping' => collect($codes)->map(fn (string $code, string $key) => [
                'role' => self::ROLES[$key] ?? $key,
                'code' => $code,
                'account' => $accounts[$code] ?? null,
            ])->values(),
        ]);
    }

    public function update(AccountingSettingsRequest $request): RedirectResponse
    {
        $before = setting('accounting.auto_journal', '1') === '1';
        $after = (bool) $request->validated('auto_journal');

        setting(['accounting.auto_journal' => $after]);

        if ($before !== $after) {
            activity('pengaturan')->causedBy($request->user())->event('updated')
                ->withProperties(['old' => ['jurnal_otomatis' => $before ? 'ya' : 'tidak'], 'attributes' => ['jurnal_otomatis' => $after ? 'ya' : 'tidak']])
                ->log($after ? 'Jurnal otomatis dinyalakan' : 'Jurnal otomatis dimatikan');
        }

        return redirect()->route('settings.accounting.edit')->with('success', $after
            ? 'Jurnal otomatis menyala. Transaksi baru langsung dijurnal.'
            : 'Jurnal otomatis dimatikan. Transaksi baru tidak dijurnal sampai dinyalakan lagi.');
    }
}
