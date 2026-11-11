<?php

namespace App\Services\CashBank;

use App\Enums\AccountType;
use App\Enums\CashBankKind;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\User;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\LedgerService;
use App\Support\Uang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rekening kas & bank. Rekening baru memakai akun kas/bank yang sudah ada di bagan akun, atau
 * dibuatkan akun baru (kode 1-11xx untuk kas, 1-12xx untuk bank) di bawah induk Kas/Bank.
 * Saldo rekening = saldo akunnya dari jurnal posted, di cabang yang terlihat.
 */
class CashBankAccountService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AccountMappingService $mapping,
    ) {}

    /** @param array<string, mixed> $data kind, name, bank_name, account_number, account_holder, imprest_amount, branch_id, note, account_id (opsional) */
    public function create(array $data, User $user): CashBankAccount
    {
        return DB::transaction(function () use ($data, $user) {
            $kind = CashBankKind::from($data['kind']);
            $account = ! empty($data['account_id']) ? $this->existingAccount((int) $data['account_id']) : $this->newAccount($kind, $data['name']);

            $rekening = new CashBankAccount($this->fields($data, $kind));
            $rekening->forceFill(['account_id' => $account->getKey()])->save();

            activity('akuntansi')->performedOn($rekening)->causedBy($user)->event('created')
                ->withProperties(['akun' => $account->label()])
                ->log("Rekening {$rekening->name} ditambahkan ({$account->label()})");

            return $rekening;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(CashBankAccount $rekening, array $data): CashBankAccount
    {
        $kind = CashBankKind::from($data['kind']);
        if (! ($data['is_active'] ?? true) && in_array($rekening->account_id, $this->mapping->cashBankAccountIds(), true)) {
            throw ValidationException::withMessages(['is_active' => 'Akun rekening ini dipakai metode pembayaran di Pengaturan → Akuntansi, jadi tidak bisa dinonaktifkan.']);
        }

        $rekening->update($this->fields($data, $kind) + ['is_active' => (bool) ($data['is_active'] ?? true)]);

        return $rekening;
    }

    /**
     * Buatkan rekening untuk akun kas/bank di bagan akun yang belum punya (misal sesudah COA standar
     * dipasang). Akun berkode 1-11xx menjadi Kas, selainnya Bank. Mengembalikan jumlah rekening baru.
     */
    public function linkCashBankAccounts(): int
    {
        $accounts = Account::query()->cashBank()
            ->whereNotIn('id', CashBankAccount::withTrashed()->select('account_id'))
            ->ordered()->get();

        foreach ($accounts as $account) {
            $rekening = new CashBankAccount([
                'kind' => str_starts_with($account->code, '1-11') ? CashBankKind::Cash : CashBankKind::Bank,
                'name' => $account->name,
                'is_active' => true,
            ]);
            $rekening->forceFill(['account_id' => $account->getKey()])->save();
        }

        return $accounts->count();
    }

    /**
     * Saldo setiap rekening (sen, akun bersaldo normal debit) dari jurnal posted.
     *
     * @param  Collection<int, CashBankAccount>  $rekening
     * @return array<int, int> id rekening => saldo
     */
    public function balances(Collection $rekening): array
    {
        $sums = $this->ledger->lines([])
            ->whereIn('jl.account_id', $rekening->pluck('account_id')->all() ?: [0])
            ->groupBy('jl.account_id')
            ->selectRaw('jl.account_id, COALESCE(SUM(jl.debit), 0) as debit, COALESCE(SUM(jl.credit), 0) as credit')
            ->get()->keyBy('account_id');

        return $rekening->mapWithKeys(fn (CashBankAccount $r) => [
            $r->id => Uang::sen($sums[$r->account_id]->debit ?? 0) - Uang::sen($sums[$r->account_id]->credit ?? 0),
        ])->all();
    }

    public function balance(CashBankAccount $rekening): int
    {
        return $this->balances(collect([$rekening]))[$rekening->id];
    }

    /** Kekurangan kas kecil terhadap plafonnya (sen), untuk pengisian ulang. */
    public function replenishAmount(CashBankAccount $rekening): int
    {
        return $rekening->isPettyCash() ? max(0, Uang::sen($rekening->imprest_amount ?? 0) - $this->balance($rekening)) : 0;
    }

    /** @return array<string, mixed> */
    private function fields(array $data, CashBankKind $kind): array
    {
        return [
            'kind' => $kind,
            'name' => trim($data['name']),
            'bank_name' => $kind === CashBankKind::Bank ? ($data['bank_name'] ?? null) : null,
            'account_number' => $kind === CashBankKind::Bank ? ($data['account_number'] ?? null) : null,
            'account_holder' => $kind === CashBankKind::Bank ? ($data['account_holder'] ?? null) : null,
            'imprest_amount' => $kind === CashBankKind::PettyCash ? ($data['imprest_amount'] ?? null) : null,
            'branch_id' => $data['branch_id'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }

    private function existingAccount(int $id): Account
    {
        $account = Account::query()->find($id);

        if ($account === null || ! $account->isPostable() || $account->type !== AccountType::Asset) {
            throw ValidationException::withMessages(['account_id' => 'Pilih akun Aset yang aktif dan bukan akun induk.']);
        }
        if (CashBankAccount::withTrashed()->where('account_id', $id)->exists()) {
            throw ValidationException::withMessages(['account_id' => "Akun {$account->label()} sudah dipakai rekening lain."]);
        }
        if (! $account->is_cash_bank) {
            $account->forceFill(['is_cash_bank' => true])->save();
        }

        return $account;
    }

    /** Akun baru di bawah induk akun Kas (1-11xx) atau Bank (1-12xx). */
    private function newAccount(CashBankKind $kind, string $name): Account
    {
        $prefix = $kind === CashBankKind::Bank ? '1-12' : '1-11';
        $sibling = Account::query()->where('code', $kind === CashBankKind::Bank ? '1-1200' : '1-1100')->first();
        $parentId = $sibling?->parent_id ?? Account::query()->where('code', '1-1000')->value('id');
        if ($parentId === null) {
            throw new AturanBisnisException('Bagan akun belum dipasang. Pasang COA standar dulu di Akuntansi → Bagan Akun.');
        }

        $taken = Account::withTrashed()->where('code', 'like', $prefix.'%')->pluck('code')->all();
        $code = null;
        foreach (range(1, 99) as $n) {
            if (! in_array($candidate = $prefix.sprintf('%02d', $n), $taken, true)) {
                $code = $candidate;

                break;
            }
        }
        if ($code === null) {
            throw new AturanBisnisException("Kode akun {$prefix}01–{$prefix}99 sudah terpakai semua. Pilih akun yang sudah ada.");
        }

        return Account::create([
            'code' => $code,
            'name' => mb_substr($name, 0, 100),
            'type' => AccountType::Asset,
            'normal_balance' => AccountType::Asset->normalBalance(),
            'parent_id' => $parentId,
            'is_header' => false,
            'is_cash_bank' => true,
            'is_active' => true,
            'description' => $kind->label().' (rekening kas & bank)',
        ]);
    }
}
