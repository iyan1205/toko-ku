<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use Illuminate\Validation\ValidationException;

/**
 * Aturan bagan akun:
 *   - akun induk harus satu kelompok dengan akunnya;
 *   - akun sistem (dipakai jurnal otomatis) tidak bisa diganti kode/kelompoknya, dijadikan akun
 *     induk, dinonaktifkan, atau dihapus;
 *   - akun yang sudah dipakai jurnal tidak bisa dijadikan akun induk atau dihapus (nonaktifkan saja);
 *   - akun induk yang masih punya akun di bawahnya tidak bisa dihapus.
 */
class AccountService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Account
    {
        $data = $this->normalize($data);
        $this->ensureParentMatches($data);

        return Account::create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(Account $account, array $data): Account
    {
        $data = $this->normalize($data);
        $this->ensureParentMatches($data);

        if ($account->is_system) {
            $errors = array_filter([
                'code' => $data['code'] !== $account->code ? 'Kode akun sistem tidak bisa diganti karena dipakai jurnal otomatis.' : null,
                'type' => $data['type'] !== $account->type->value ? 'Kelompok akun sistem tidak bisa diganti.' : null,
                'is_header' => $data['is_header'] ? 'Akun sistem tidak bisa dijadikan akun induk.' : null,
                'is_active' => ! $data['is_active'] ? 'Akun sistem tidak bisa dinonaktifkan karena dipakai jurnal otomatis.' : null,
            ]);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        if ($data['is_header'] && ! $account->is_header && $account->lines()->exists()) {
            throw ValidationException::withMessages(['is_header' => 'Akun yang sudah dipakai di jurnal tidak bisa dijadikan akun induk.']);
        }

        $account->update($data);

        return $account;
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw new AturanBisnisException("Akun {$account->label()} dipakai jurnal otomatis dan tidak bisa dihapus.");
        }
        if ($account->lines()->exists()) {
            throw new AturanBisnisException("Akun {$account->label()} sudah dipakai di jurnal, jadi tidak bisa dihapus. Nonaktifkan saja.");
        }
        if ($account->children()->exists()) {
            throw new AturanBisnisException("Akun {$account->label()} masih punya akun di bawahnya. Pindahkan atau hapus akun tersebut dulu.");
        }

        $account->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $data['normal_balance'] ??= AccountType::from($data['type'])->normalBalance()->value;

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function ensureParentMatches(array $data): void
    {
        $parent = isset($data['parent_id']) ? Account::find($data['parent_id']) : null;

        if ($parent && $parent->type->value !== $data['type']) {
            throw ValidationException::withMessages(['parent_id' => "Akun induk {$parent->label()} berada di kelompok {$parent->type->label()}, sedangkan akun ini {$this->typeLabel($data['type'])}."]);
        }
    }

    private function typeLabel(string $type): string
    {
        return AccountType::from($type)->label();
    }
}
