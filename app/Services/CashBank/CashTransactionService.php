<?php

namespace App\Services\CashBank;

use App\Enums\CashTransactionType;
use App\Enums\DocumentStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\CashTransaction;
use App\Models\CostCenter;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\Accounting\JournalLines;
use App\Services\Accounting\JournalService;
use App\Services\Approval\ApprovalService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan kas, pengeluaran kas, dan transfer antar rekening (termasuk pengisian kas kecil).
 *
 *   penerimaan : D akun rekening | K akun per baris (pendapatan lain, modal, pinjaman, ...)
 *   pengeluaran: D akun per baris (beban, aset, ...) | K akun rekening
 *   transfer   : D rekening tujuan, D biaya admin (bila ada) | K rekening asal
 *
 * Alur status sama dengan jurnal manual: draf → (diajukan → disetujui) → diposting. Pengeluaran
 * dan transfer mengikuti alur persetujuan cash_out/cash_transfer bila ada yang cocok. Posting
 * membuat jurnal langsung (bukan listener): transaksi kas tanpa jurnal tidak ada artinya.
 * Transaksi posted dibatalkan dengan jurnal pembalik bertanggal hari pembatalan.
 */
class CashTransactionService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ApprovalService $approvals,
        private readonly AccountMappingService $mapping,
    ) {}

    /** @param array<string, mixed> $data transaction_date, cash_bank_account_id, target_cash_bank_account_id, counterparty, reference, description, amount, fee, fee_account_id, lines[] */
    public function saveDraft(CashTransactionType $type, array $data, User $user, ?CashTransaction $draft = null): CashTransaction
    {
        $date = CarbonImmutable::parse($data['transaction_date'])->toDateString();
        app(FiscalPeriodService::class)->ensureOpen($date);
        [$rekening, $target, $rows, $amount, $fee, $feeAccount] = $this->validated($type, $data);

        return DB::transaction(function () use ($type, $data, $date, $rekening, $target, $rows, $amount, $fee, $feeAccount, $user, $draft) {
            $transaction = $draft ? $this->lock($draft) : new CashTransaction(['type' => $type]);

            if ($draft && ! $transaction->isEditable()) {
                throw new AturanBisnisException("{$transaction->documentLabel()} berstatus {$transaction->documentStatus()->label()} dan tidak bisa diubah.");
            }

            $transaction->fill([
                'transaction_date' => $date,
                'cash_bank_account_id' => $rekening->getKey(),
                'target_cash_bank_account_id' => $target?->getKey(),
                'counterparty' => $data['counterparty'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => mb_substr(trim((string) $data['description']), 0, 255),
                'amount' => Uang::keDesimal($amount),
                'fee' => Uang::keDesimal($fee),
                'fee_account_id' => $feeAccount?->getKey(),
            ]);

            if (! $transaction->exists) {
                $transaction->forceFill(['status' => DocumentStatus::Draft]);
                $transaction->assignDocumentNumber();
            }

            $transaction->save();
            $transaction->lines()->delete();
            foreach ($rows as $row) {
                $transaction->lines()->create($row);
            }

            activity('akuntansi')->performedOn($transaction)->causedBy($user)->event($draft ? 'draft_updated' : 'draft_created')
                ->withProperties(['jumlah' => $transaction->amount, 'rekening' => $rekening->name])
                ->log(($draft ? 'Draf diubah: ' : 'Draf dibuat: ')."{$type->label()} {$transaction->number}");

            return $transaction->load('lines');
        });
    }

    /** Posting, atau ajukan dulu bila ada alur persetujuan yang cocok. */
    public function postOrSubmit(CashTransaction $transaction, User $user): CashTransaction
    {
        return $this->approvals->requiresApproval($transaction)
            ? $this->submit($transaction, $user)
            : $this->post($transaction, $user);
    }

    public function submit(CashTransaction $transaction, User $user, ?string $note = null): CashTransaction
    {
        return DB::transaction(function () use ($transaction, $user, $note) {
            $locked = $this->lock($transaction);

            if (! $locked->isEditable()) {
                throw new AturanBisnisException("{$locked->documentLabel()} berstatus {$locked->documentStatus()->label()} dan tidak bisa diajukan.");
            }

            app(FiscalPeriodService::class)->ensureOpen($locked->transaction_date);
            $this->approvals->submit($locked, $user, $note);

            return $locked->refresh();
        });
    }

    /** Posting draf (tanpa alur persetujuan) atau yang sudah disetujui: dijurnal. */
    public function post(CashTransaction $transaction, User $user): CashTransaction
    {
        return DB::transaction(function () use ($transaction, $user) {
            $locked = $this->lock($transaction)->load(['lines', 'cashBankAccount.account', 'targetCashBankAccount.account']);
            $status = $locked->documentStatus();

            if (! in_array($status, [DocumentStatus::Draft, DocumentStatus::Approved], true)) {
                throw new AturanBisnisException("{$locked->documentLabel()} berstatus {$status->label()} dan tidak bisa diposting.");
            }
            if ($status === DocumentStatus::Draft && ($flow = $this->approvals->flowFor($locked)) !== null) {
                throw new AturanBisnisException("{$locked->documentLabel()} perlu persetujuan ({$flow->name}). Ajukan persetujuan dulu.");
            }

            $this->journals->post($locked->transaction_date, $this->journalDescription($locked), $this->journalLines($locked), $user, $locked, 'posted',
                attributes: ['branch_id' => $locked->branch_id]);
            $locked->moveToStatus(DocumentStatus::Posted, $user);

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('posted')
                ->withProperties(['jumlah' => $locked->amount])
                ->log("{$locked->type->label()} {$locked->number} diposting");

            return $locked;
        });
    }

    /** Batalkan draf/pengajuan, atau transaksi posted (jurnal pembalik hari ini). */
    public function cancel(CashTransaction $transaction, User $user, string $reason): CashTransaction
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($transaction, $user, $reason) {
            $locked = $this->lock($transaction);
            $status = $locked->documentStatus();

            if ($status === DocumentStatus::Cancelled) {
                throw new AturanBisnisException("{$locked->documentLabel()} sudah dibatalkan.");
            }

            if ($status === DocumentStatus::Posted) {
                $journal = $this->journals->find($locked, 'posted');
                if ($journal !== null && ! $journal->reversal()->exists()) {
                    $this->journals->reverse($journal, "Pembatalan {$locked->number}: {$reason}", $user, $locked, 'voided');
                }
            } elseif (($pending = $locked->pendingApproval()) !== null) {
                $this->approvals->cancel($pending, $user, $reason, force: true);
                $locked->refresh();
            }

            $locked->moveToStatus(DocumentStatus::Cancelled, $user, mb_substr($reason, 0, 255));

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('cancelled')
                ->withProperties(['alasan' => $reason, 'status_sebelumnya' => $status->label()])
                ->log("{$locked->type->label()} {$locked->number} dibatalkan");

            return $locked;
        });
    }

    /** Draf baru lalu posting (atau ajukan), misal dari rekonsiliasi bank. */
    public function createPosted(CashTransactionType $type, array $data, User $user): CashTransaction
    {
        return DB::transaction(fn () => $this->postOrSubmit($this->saveDraft($type, $data, $user), $user));
    }

    private function journalDescription(CashTransaction $transaction): string
    {
        $party = $transaction->counterparty ? " · {$transaction->counterparty}" : '';

        return "{$transaction->type->label()} {$transaction->number} · {$transaction->description}{$party}";
    }

    /** @return list<array<string, mixed>> baris jurnal dalam Rupiah */
    private function journalLines(CashTransaction $transaction): array
    {
        $lines = new JournalLines;
        $rekening = $transaction->cashBankAccount->account;
        $amount = Uang::sen($transaction->amount);

        if ($transaction->type === CashTransactionType::Transfer) {
            $fee = Uang::sen($transaction->fee);
            $lines->debit($transaction->targetCashBankAccount->account, $amount, "Dari {$transaction->cashBankAccount->name}");
            if ($fee > 0) {
                $lines->debit(Account::withTrashed()->findOrFail($transaction->fee_account_id), $fee, 'Biaya transfer');
            }
            $lines->credit($rekening, $amount + $fee, "Ke {$transaction->targetCashBankAccount->name}");

            return $lines->all();
        }

        // Baris dengan cost center dijurnal satu per satu agar cost center-nya tersimpan.
        $rows = [];
        foreach ($transaction->lines as $line) {
            $rows[] = [
                'account_id' => $line->account_id,
                'cost_center_id' => $line->cost_center_id,
                'description' => $line->description,
                'debit' => $transaction->type === CashTransactionType::Out ? (string) $line->amount : '0',
                'credit' => $transaction->type === CashTransactionType::In ? (string) $line->amount : '0',
            ];
        }
        $side = $transaction->type === CashTransactionType::In ? 'debit' : 'credit';
        $rows[] = ['account_id' => $rekening->id, 'cost_center_id' => null, 'description' => $transaction->cashBankAccount->name,
            'debit' => $side === 'debit' ? Uang::keDesimal($amount) : '0', 'credit' => $side === 'credit' ? Uang::keDesimal($amount) : '0'];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: CashBankAccount, 1: ?CashBankAccount, 2: list<array<string, mixed>>, 3: int, 4: int, 5: ?Account}
     */
    private function validated(CashTransactionType $type, array $data): array
    {
        $errors = [];
        $rekening = CashBankAccount::query()->available()->active()->find((int) ($data['cash_bank_account_id'] ?? 0));
        if ($rekening === null) {
            $errors['cash_bank_account_id'] = 'Pilih rekening yang aktif.';
        }
        if (blank($data['description'] ?? null)) {
            $errors['description'] = 'Isi keterangan.';
        }

        $target = null;
        $rows = [];
        $fee = 0;
        $feeAccount = null;

        if ($type === CashTransactionType::Transfer) {
            $target = CashBankAccount::query()->available()->active()->find((int) ($data['target_cash_bank_account_id'] ?? 0));
            $amount = Uang::sen($data['amount'] ?? 0);
            $fee = Uang::sen($data['fee'] ?? 0);
            if ($target === null) {
                $errors['target_cash_bank_account_id'] = 'Pilih rekening tujuan yang aktif.';
            } elseif ($rekening && $target->is($rekening)) {
                $errors['target_cash_bank_account_id'] = 'Rekening tujuan harus berbeda dengan rekening asal.';
            }
            if ($amount <= 0) {
                $errors['amount'] = 'Jumlah transfer harus lebih dari nol.';
            }
            if ($fee < 0) {
                $errors['fee'] = 'Biaya tidak boleh negatif.';
            } elseif ($fee > 0) {
                $feeAccount = ! empty($data['fee_account_id']) ? Account::query()->find((int) $data['fee_account_id']) : $this->mapping->optional('bank_fee');
                if ($feeAccount === null || ! $feeAccount->isPostable()) {
                    $errors['fee_account_id'] = 'Pilih akun biaya transfer.';
                }
            }
        } else {
            $lines = array_values($data['lines'] ?? []);
            $accounts = Account::query()->whereIn('id', array_map(fn ($l) => (int) ($l['account_id'] ?? 0), $lines) ?: [0])->get()->keyBy('id');
            $costCenters = CostCenter::query()->whereIn('id', array_map(fn ($l) => (int) ($l['cost_center_id'] ?? 0), $lines) ?: [0])->get()->keyBy('id');
            $amount = 0;

            foreach ($lines as $i => $line) {
                $sen = Uang::sen($line['amount'] ?? 0);
                $account = $accounts[(int) ($line['account_id'] ?? 0)] ?? null;
                if ($sen === 0 && $account === null) {
                    continue;
                }
                $costCenter = ! empty($line['cost_center_id']) ? ($costCenters[(int) $line['cost_center_id']] ?? null) : null;

                if ($account === null || ! $account->isPostable()) {
                    $errors["lines.{$i}.account_id"] = 'Pilih akun yang aktif untuk baris ini.';
                } elseif ($rekening && $account->is($rekening->account)) {
                    $errors["lines.{$i}.account_id"] = 'Akun lawan tidak boleh akun rekening itu sendiri. Untuk antar rekening, pakai Transfer.';
                } elseif ($sen <= 0) {
                    $errors["lines.{$i}.amount"] = 'Jumlah harus lebih dari nol.';
                } elseif (! empty($line['cost_center_id']) && ($costCenter === null || ! $costCenter->is_active)) {
                    $errors["lines.{$i}.cost_center_id"] = 'Cost center tidak aktif.';
                } else {
                    $amount += $sen;
                    $rows[] = [
                        'account_id' => $account->id,
                        'cost_center_id' => $costCenter?->id,
                        'description' => blank($line['description'] ?? null) ? null : mb_substr(trim($line['description']), 0, 255),
                        'amount' => Uang::keDesimal($sen),
                    ];
                }
            }

            if ($errors === [] && $rows === []) {
                $errors['lines'] = 'Isi minimal satu baris akun dan jumlahnya.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$rekening, $target, $rows, $amount, $fee, $feeAccount];
    }

    private function lock(CashTransaction $transaction): CashTransaction
    {
        return CashTransaction::withoutGlobalScope(BranchScope::class)->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();
    }
}
