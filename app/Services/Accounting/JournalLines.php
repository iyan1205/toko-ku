<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Support\Uang;

/**
 * Penyusun baris jurnal otomatis: nilai dalam sen, baris bernilai nol dilewati, dan baris
 * dengan akun + sisi + keterangan yang sama digabung (misal QRIS dan transfer sama-sama ke Bank).
 */
final class JournalLines
{
    /** @var array<string, array{account_id: int, description: ?string, debit: int, credit: int}> */
    private array $lines = [];

    public function debit(Account $account, int $sen, ?string $description = null): self
    {
        return $this->add($account, $sen, 'debit', $description);
    }

    public function credit(Account $account, int $sen, ?string $description = null): self
    {
        return $this->add($account, $sen, 'credit', $description);
    }

    /** @return list<array{account_id: int, description: ?string, debit: string, credit: string}> */
    public function all(): array
    {
        return array_values(array_map(fn (array $line) => [
            'account_id' => $line['account_id'],
            'description' => $line['description'],
            'debit' => Uang::keDesimal($line['debit']),
            'credit' => Uang::keDesimal($line['credit']),
        ], $this->lines));
    }

    private function add(Account $account, int $sen, string $side, ?string $description): self
    {
        if ($sen <= 0) {
            return $this;
        }

        $key = "{$account->id}|{$side}|{$description}";
        $this->lines[$key] ??= ['account_id' => $account->id, 'description' => $description, 'debit' => 0, 'credit' => 0];
        $this->lines[$key][$side] += $sen;

        return $this;
    }
}
