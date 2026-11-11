<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AccountingSettingsRequest;
use App\Models\Account;
use App\Services\Accounting\AccountMappingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Pengaturan → Akuntansi: nyala/mati jurnal otomatis dan pemetaan akun yang dipakainya
 * (penjualan, diskon, PPN, HPP, persediaan, hutang, piutang, kas per metode pembayaran, gaji, ...).
 */
class AccountingSettingController extends Controller
{
    public function __construct(private readonly AccountMappingService $mapping) {}

    public function edit(): View
    {
        $accounts = Account::query()->postable()->ordered()->get(['id', 'code', 'name', 'type', 'is_cash_bank']);

        return view('settings.accounting', [
            'enabled' => setting('accounting.auto_journal', '1') === '1',
            'installed' => Account::query()->exists(),
            'roles' => $this->mapping->current()->groupBy(fn (array $role) => $role['definition']['group']),
            'accounts' => $accounts,
            'paymentMethods' => collect($this->mapping->paymentMethods())->map(fn ($method) => [
                'method' => $method,
                'account_id' => $this->mapping->paymentAccountId($method),
                'fallback' => $this->mapping->account($this->mapping->paymentRole($method)),
            ]),
        ]);
    }

    public function update(AccountingSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $before = setting('accounting.auto_journal', '1') === '1';
        $after = (bool) $data['auto_journal'];

        // Pemetaan hanya dikirim bila bagan akun sudah dipasang.
        if (array_key_exists('roles', $data)) {
            $this->mapping->save($data['roles'] ?? [], $data['payments'] ?? [], $request->user());
        }

        setting(['accounting.auto_journal' => $after]);

        if ($before !== $after) {
            activity('pengaturan')->causedBy($request->user())->event('updated')
                ->withProperties(['old' => ['jurnal_otomatis' => $before ? 'ya' : 'tidak'], 'attributes' => ['jurnal_otomatis' => $after ? 'ya' : 'tidak']])
                ->log($after ? 'Jurnal otomatis dinyalakan' : 'Jurnal otomatis dimatikan');
        }

        return redirect()->route('settings.accounting.edit')->with('success', $after
            ? 'Pengaturan akuntansi disimpan. Transaksi baru dijurnal dengan akun di halaman ini.'
            : 'Jurnal otomatis dimatikan. Transaksi baru tidak dijurnal sampai dinyalakan lagi.');
    }
}
