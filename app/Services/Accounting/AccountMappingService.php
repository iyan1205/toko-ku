<?php

namespace App\Services\Accounting;

use App\Enums\PaymentMethod;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Pemetaan akun untuk jurnal otomatis (Pengaturan → Akuntansi).
 *
 * Peran akun didefinisikan di config/accounting.php ('mapping'). Pilihan pemilik disimpan di
 * setting accounting.map.{peran} (id akun; NONE = sengaja dikosongkan untuk peran opsional) dan
 * accounting.map.payment.{metode}. Tanpa pilihan, dipakai akun dengan kode bawaan.
 *
 * Akun yang sedang dipetakan ditandai akun sistem (syncSystemFlags) agar tidak dihapus,
 * dinonaktifkan, atau diganti kode/kelompoknya.
 */
class AccountMappingService
{
    /** Nilai setting untuk peran opsional yang sengaja tidak dipakai (setting kosong = kembali ke bawaan). */
    public const NONE = 'none';

    /** @return array<string, array{group: string, label: string, default: string, types: list<string>, optional?: bool, cash_bank?: bool, hint?: string}> */
    public function definitions(): array
    {
        return config('accounting.mapping', []);
    }

    /**
     * Metode pembayaran yang bisa diberi akun sendiri. Piutang bukan uang masuk, jadi tidak dipetakan.
     *
     * @return list<PaymentMethod>
     */
    public function paymentMethods(): array
    {
        return array_values(array_filter(PaymentMethod::configurable(), fn (PaymentMethod $m) => $m !== PaymentMethod::Receivable));
    }

    /** Akun untuk peran ini, atau null bila belum ada / dikosongkan. */
    public function account(string $key): ?Account
    {
        $definition = $this->definitions()[$key] ?? throw new InvalidArgumentException("Peran akun [{$key}] tidak dikenal.");
        $chosen = setting("accounting.map.{$key}");

        return match (true) {
            $chosen === null => Account::query()->where('code', $definition['default'])->first(),
            $chosen === self::NONE => null,
            default => Account::query()->find((int) $chosen),
        };
    }

    /** Akun wajib untuk jurnal otomatis: ada, aktif, dan bukan akun induk. */
    public function require(string $key): Account
    {
        $account = $this->account($key);

        if ($account === null || ! $account->isPostable()) {
            $definition = $this->definitions()[$key];
            $status = $account ? "({$account->label()}) tidak aktif atau berupa akun induk" : "belum ada (kode bawaan {$definition['default']})";

            throw new AturanBisnisException("Akun untuk jurnal otomatis \"{$definition['label']}\" {$status}. Atur di Pengaturan → Akuntansi atau Bagan Akun.");
        }

        return $account;
    }

    /** Akun peran opsional bila dipetakan ke akun yang bisa dipakai; null = tidak dipisah. */
    public function optional(string $key): ?Account
    {
        $account = $this->account($key);

        return $account?->isPostable() ? $account : null;
    }

    /** Akun penampung uang untuk metode pembayaran: akun khusus metode itu, atau Kas/Bank. */
    public function paymentAccount(PaymentMethod $method): Account
    {
        $chosen = setting("accounting.map.payment.{$method->value}");

        if ($chosen !== null && (string) $chosen !== '') {
            $account = Account::query()->find((int) $chosen);

            if ($account === null || ! $account->isPostable()) {
                throw new AturanBisnisException("Akun untuk metode pembayaran {$method->label()} tidak ada atau tidak aktif. Atur di Pengaturan → Akuntansi.");
            }

            return $account;
        }

        return $this->require($this->paymentRole($method));
    }

    /** Peran bawaan metode pembayaran: tunai → cash, lainnya → bank. */
    public function paymentRole(PaymentMethod $method): string
    {
        return config("accounting.payment_accounts.{$method->value}", 'bank');
    }

    /** Id akun khusus metode pembayaran (null = ikut Kas/Bank). */
    public function paymentAccountId(PaymentMethod $method): ?int
    {
        $chosen = setting("accounting.map.payment.{$method->value}");

        return $chosen !== null && (string) $chosen !== '' ? (int) $chosen : null;
    }

    /**
     * Simpan pemetaan. $roles: peran => id akun (null/'' untuk peran opsional yang dikosongkan).
     * $payments: metode => id akun (null/'' = ikut Kas/Bank).
     *
     * @param  array<string, mixed>  $roles
     * @param  array<string, mixed>  $payments
     */
    public function save(array $roles, array $payments, User $actor): void
    {
        $definitions = $this->definitions();
        $ids = array_filter(array_map(fn ($v) => (int) $v, [...array_values($roles), ...array_values($payments)]));
        $accounts = Account::query()->whereIn('id', $ids)->get()->keyBy('id');
        $errors = [];
        $pairs = [];

        foreach ($definitions as $key => $definition) {
            $id = (int) ($roles[$key] ?? 0);

            if ($id === 0) {
                if (! ($definition['optional'] ?? false)) {
                    $errors["roles.{$key}"] = "Pilih akun untuk {$definition['label']}.";
                }
                $pairs["accounting.map.{$key}"] = self::NONE;

                continue;
            }

            if ($message = $this->unsuitable($accounts[$id] ?? null, $definition['types'], $definition['cash_bank'] ?? false)) {
                $errors["roles.{$key}"] = $message;
            }
            $pairs["accounting.map.{$key}"] = (string) $id;
        }

        foreach ($this->paymentMethods() as $method) {
            $id = (int) ($payments[$method->value] ?? 0);

            if ($id !== 0 && ($message = $this->unsuitable($accounts[$id] ?? null, ['asset'], true))) {
                $errors["payments.{$method->value}"] = $message;
            }
            $pairs["accounting.map.payment.{$method->value}"] = $id === 0 ? '' : (string) $id;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($pairs, $actor) {
            $before = $this->snapshot();
            setting($pairs);
            $after = $this->snapshot();
            $this->syncSystemFlags();

            $changed = array_keys(array_diff_assoc($after, $before));
            if ($changed !== []) {
                activity('pengaturan')->causedBy($actor)->event('updated')
                    ->withProperties([
                        'old' => array_intersect_key($before, array_flip($changed)),
                        'attributes' => array_intersect_key($after, array_flip($changed)),
                    ])
                    ->log('Pemetaan akun jurnal otomatis diubah');
            }
        });
    }

    /**
     * Semua peran dengan akunnya saat ini, untuk halaman pengaturan.
     *
     * @return Collection<string, array{key: string, definition: array<string, mixed>, account: ?Account}>
     */
    public function current(): Collection
    {
        return collect($this->definitions())->map(fn (array $definition, string $key) => [
            'key' => $key,
            'definition' => $definition,
            'account' => $this->account($key),
        ]);
    }

    /** @return list<int> id akun yang sedang dipetakan (peran + metode pembayaran) */
    public function mappedAccountIds(): array
    {
        $ids = [];
        foreach (array_keys($this->definitions()) as $key) {
            $ids[] = $this->account($key)?->getKey();
        }
        foreach ($this->paymentMethods() as $method) {
            $ids[] = $this->paymentAccountId($method);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** @return list<int> akun yang dipetakan sebagai kas/bank (harus tetap bertanda kas/bank) */
    public function cashBankAccountIds(): array
    {
        $ids = [];
        foreach ($this->definitions() as $key => $definition) {
            if ($definition['cash_bank'] ?? false) {
                $ids[] = $this->account($key)?->getKey();
            }
        }
        foreach ($this->paymentMethods() as $method) {
            $ids[] = $this->paymentAccountId($method);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** Tandai akun yang dipetakan sebagai akun sistem dan lepaskan tanda dari akun lain. */
    public function syncSystemFlags(): void
    {
        $ids = $this->mappedAccountIds();

        Account::withTrashed()->whereIn('id', $ids ?: [0])->where('is_system', false)->update(['is_system' => true]);
        Account::withTrashed()->whereNotIn('id', $ids ?: [0])->where('is_system', true)->update(['is_system' => false]);
    }

    /** @param list<string> $types */
    private function unsuitable(?Account $account, array $types, bool $cashBank): ?string
    {
        return match (true) {
            $account === null => 'Akun tidak ditemukan.',
            ! $account->isPostable() => "Akun {$account->label()} tidak aktif atau berupa akun induk.",
            ! in_array($account->type->value, $types, true) => "Akun {$account->label()} berkelompok {$account->type->label()}, tidak cocok untuk peran ini.",
            $cashBank && ! $account->is_cash_bank => "Akun {$account->label()} belum ditandai akun kas/bank di Bagan Akun.",
            default => null,
        };
    }

    /** @return array<string, string> "Penjualan" => "4-1000 Penjualan" */
    private function snapshot(): array
    {
        $result = [];
        foreach ($this->definitions() as $key => $definition) {
            $result[$definition['label']] = $this->account($key)?->label() ?? '(tidak dipisah)';
        }
        foreach ($this->paymentMethods() as $method) {
            $id = $this->paymentAccountId($method);
            $result["Metode {$method->label()}"] = $id ? (Account::find($id)?->label() ?? '-') : 'Ikut '.($this->definitions()[$this->paymentRole($method)]['label'] ?? 'Bank');
        }

        return $result;
    }
}
