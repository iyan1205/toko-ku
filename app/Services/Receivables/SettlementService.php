<?php

namespace App\Services\Receivables;

use App\Enums\DocumentStatus;
use App\Enums\GiroStatus;
use App\Enums\PaymentMethod;
use App\Enums\SettlementType;
use App\Events\GiroCleared;
use App\Events\SettlementCancelled;
use App\Events\SettlementPosted;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Scopes\BranchScope;
use App\Models\Settlement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\PaymentService;
use App\Support\Format;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pelunasan piutang/hutang: satu dokumen untuk banyak faktur, sebagian atau penuh, dengan uang
 * muka dan giro. Jurnalnya dibuat listener CreateAutoJournal (SettlementPosted).
 *
 *   penerimaan/pembayaran : setiap alokasi menjadi satu baris payments (PaymentService) agar
 *                           riwayat dan sisa dokumen tetap di satu tempat. Kelebihan uang = uang muka.
 *   nota kredit/debit     : mengurangi sisa dokumen (credited_amount) tanpa uang, lawan akun dipilih.
 *   giro                  : uang masuk/keluar ke akun Giro sampai dicairkan (clearGiro) atau ditolak
 *                           (bounceGiro = pembatalan pelunasan).
 */
class SettlementService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OpenDocuments $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $data  type, customer_id|supplier_id, settlement_date, method, cash_bank_account_id,
     *                                      amount, deposit_applied, counter_account_id, giro_*, reference, description,
     *                                      allocations: array<"type:id", jumlah Rupiah>
     */
    public function create(array $data, User $user): Settlement
    {
        $type = SettlementType::from($data['type']);
        $receivable = $type->isReceivable();
        $partyId = (int) ($receivable ? ($data['customer_id'] ?? 0) : ($data['supplier_id'] ?? 0));
        $date = CarbonImmutable::parse($data['settlement_date'])->toDateString();
        app(FiscalPeriodService::class)->ensureOpen($date);

        $party = $receivable ? Customer::find($partyId) : Supplier::find($partyId);
        if ($party === null) {
            throw ValidationException::withMessages([$receivable ? 'customer_id' : 'supplier_id' => 'Pilih '.($receivable ? 'pelanggan' : 'supplier').'.']);
        }

        $allocations = $this->allocations($data['allocations'] ?? []);
        $allocated = array_sum(array_column($allocations, 'sen'));
        $amount = Uang::sen($data['amount'] ?? 0);
        $depositApplied = $type->isCash() ? Uang::sen($data['deposit_applied'] ?? 0) : 0;
        $method = $type->isCash() ? (string) ($data['method'] ?? 'cash') : null;
        $rekening = null;
        $counter = null;
        $errors = [];

        if ($type->isCash()) {
            if (! in_array($method, ['cash', 'transfer', 'giro'], true)) {
                $errors['method'] = 'Pilih cara bayar: tunai, transfer, atau giro/cek.';
            }
            if ($method === 'giro') {
                if (blank($data['giro_number'] ?? null)) {
                    $errors['giro_number'] = 'Isi nomor giro/cek.';
                }
                if (blank($data['giro_due_date'] ?? null)) {
                    $errors['giro_due_date'] = 'Isi tanggal jatuh tempo giro/cek.';
                }
            } else {
                $rekening = CashBankAccount::query()->available()->active()->find((int) ($data['cash_bank_account_id'] ?? 0));
                if ($rekening === null) {
                    $errors['cash_bank_account_id'] = 'Pilih rekening kas/bank.';
                }
            }
            if ($amount < 0 || $depositApplied < 0) {
                $errors['amount'] = 'Nilai tidak boleh negatif.';
            }
            if ($depositApplied > 0 && $depositApplied > $this->depositBalance($receivable, $partyId)) {
                $errors['deposit_applied'] = 'Uang muka yang dipakai melebihi saldo uang muka '.Format::rupiah(Uang::keDesimal($this->depositBalance($receivable, $partyId))).'.';
            }
            if ($amount + $depositApplied <= 0) {
                $errors['amount'] = 'Isi jumlah uang yang '.($receivable ? 'diterima' : 'dibayar').'.';
            } elseif ($allocated > $amount + $depositApplied) {
                $errors['allocations'] = sprintf('Jumlah yang dialokasikan (%s) melebihi uang %s ditambah uang muka (%s).',
                    Format::rupiah(Uang::keDesimal($allocated)), $receivable ? 'diterima' : 'dibayar', Format::rupiah(Uang::keDesimal($amount + $depositApplied)));
            }
            if ($depositApplied > 0 && $allocated < $depositApplied) {
                $errors['deposit_applied'] = 'Uang muka hanya bisa dipakai untuk melunasi faktur.';
            }
        } else {
            $counter = Account::query()->find((int) ($data['counter_account_id'] ?? 0));
            if ($counter === null || ! $counter->isPostable()) {
                $errors['counter_account_id'] = 'Pilih akun lawan nota.';
            }
            if ($allocated <= 0) {
                $errors['allocations'] = 'Isi nilai nota untuk minimal satu faktur.';
            }
            $amount = $allocated;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($data, $type, $receivable, $partyId, $party, $date, $allocations, $allocated, $amount, $depositApplied, $method, $rekening, $counter, $user) {
            $settlement = new Settlement([
                'type' => $type,
                'customer_id' => $receivable ? $partyId : null,
                'supplier_id' => $receivable ? null : $partyId,
                'settlement_date' => $date,
                'method' => $method,
                'cash_bank_account_id' => $rekening?->getKey(),
                'counter_account_id' => $counter?->getKey(),
                'giro_number' => $method === 'giro' ? trim($data['giro_number']) : null,
                'giro_bank' => $method === 'giro' ? ($data['giro_bank'] ?? null) : null,
                'giro_due_date' => $method === 'giro' ? $data['giro_due_date'] : null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
            $settlement->forceFill([
                'amount' => Uang::keDesimal($amount),
                'deposit_applied' => Uang::keDesimal($depositApplied),
                'deposit_added' => Uang::keDesimal($type->isCash() ? $amount + $depositApplied - $allocated : 0),
                'giro_status' => $method === 'giro' ? GiroStatus::Pending : null,
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $user->getKey(),
            ]);
            $settlement->assignDocumentNumber();
            $settlement->save();

            $paymentMethod = $method === 'giro' ? PaymentMethod::Transfer : ($rekening?->kind->paymentMethod() ?? PaymentMethod::Cash);
            $note = $type->label()." {$settlement->number}".($method === 'giro' ? " (giro {$settlement->giro_number})" : '');

            foreach ($allocations as $row) {
                $document = $this->documents->lock($row['type'], $row['id'], $receivable, $partyId);
                if (! $this->documents->isPayable($document)) {
                    throw new AturanBisnisException("{$document->number} sudah dibatalkan dan tidak bisa dilunasi.");
                }
                if ($row['sen'] > $this->documents->balance($document)) {
                    throw new AturanBisnisException("Nilai untuk {$document->number} melebihi sisanya (".Format::rupiah($document->balance).').');
                }

                $payment = null;
                if ($type->isCash()) {
                    $payment = match (true) {
                        $document instanceof Sale => $this->payments->terimaPenjualan($document, $row['sen'], $paymentMethod, $note, $user, $date, atCheckout: true, settlement: $settlement),
                        $document instanceof Purchase => $this->payments->bayarPembelian($document, $row['sen'], $paymentMethod, $note, $user, $date, atCheckout: true, settlement: $settlement),
                        $document instanceof Invoice => $this->payments->bayarFaktur($document, $row['sen'], $paymentMethod, $note, $user, $date, $settlement),
                    };
                } else {
                    $this->documents->applyCredit($document, $row['sen']);
                }

                $settlement->allocations()->create([
                    'document_type' => $row['type'],
                    'document_id' => $row['id'],
                    'amount' => Uang::keDesimal($row['sen']),
                    'payment_id' => $payment?->getKey(),
                ]);
            }

            activity($receivable ? 'penjualan' : 'pembelian')->performedOn($settlement)->causedBy($user)->event('created')
                ->withProperties(['pihak' => $party->name, 'jumlah' => $settlement->amount, 'faktur' => count($allocations), 'uang_muka_baru' => $settlement->deposit_added])
                ->log("{$type->label()} {$settlement->number} untuk {$party->name}");

            SettlementPosted::dispatch($settlement);

            return $settlement->load('allocations');
        });
    }

    /**
     * Batalkan pelunasan: pembayaran dibatalkan, nota dikembalikan, sisa faktur kembali, jurnal dibalik
     * (tanggal hari ini). Giro yang sudah cair tidak bisa dibatalkan.
     */
    public function cancel(Settlement $settlement, User $user, string $reason, bool $bounced = false): Settlement
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan minimal 5 karakter.']);
        }
        app(FiscalPeriodService::class)->ensureOpen(now());

        return DB::transaction(function () use ($settlement, $user, $reason, $bounced) {
            $locked = Settlement::withoutGlobalScope(BranchScope::class)->whereKey($settlement->getKey())->lockForUpdate()->with('allocations.payment')->firstOrFail();

            if (! $locked->isPosted()) {
                throw new AturanBisnisException("{$locked->documentLabel()} sudah dibatalkan.");
            }
            if ($locked->giro_status === GiroStatus::Cleared) {
                throw new AturanBisnisException("Giro {$locked->giro_number} sudah dicairkan, jadi pelunasan {$locked->number} tidak bisa dibatalkan. Catat koreksinya dengan jurnal.");
            }

            $receivable = $locked->type->isReceivable();
            $partyId = (int) ($receivable ? $locked->customer_id : $locked->supplier_id);
            if (Uang::sen($locked->deposit_added) > 0 && $this->depositBalance($receivable, $partyId) < Uang::sen($locked->deposit_added)) {
                throw new AturanBisnisException("Uang muka dari {$locked->number} sudah dipakai pelunasan lain. Batalkan pelunasan itu dulu.");
            }

            foreach ($locked->allocations as $allocation) {
                if ($allocation->payment !== null) {
                    $this->payments->batalkanPembayaran($allocation->payment, $user, "Pelunasan {$locked->number} dibatalkan: {$reason}");
                } else {
                    $document = $this->documents->lock($allocation->document_type, (int) $allocation->document_id, $receivable, $partyId);
                    $this->documents->revertCredit($document, Uang::sen($allocation->amount));
                }
            }

            if ($bounced) {
                $locked->forceFill(['giro_status' => GiroStatus::Bounced])->save();
            }
            $locked->moveToStatus(DocumentStatus::Cancelled, $user, mb_substr($reason, 0, 255));

            activity($receivable ? 'penjualan' : 'pembelian')->performedOn($locked)->causedBy($user)->event($bounced ? 'giro_bounced' : 'voided')
                ->withProperties(['alasan' => $reason])
                ->log(($bounced ? "Giro {$locked->giro_number} ditolak; " : '')."{$locked->type->label()} {$locked->number} dibatalkan");

            SettlementCancelled::dispatch($locked, $user);

            return $locked;
        });
    }

    /** Giro/cek cair ke rekening: pindah dari akun Giro ke akun rekening. */
    public function clearGiro(Settlement $settlement, CashBankAccount $rekening, string $date, User $user): Settlement
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        app(FiscalPeriodService::class)->ensureOpen($date);

        return DB::transaction(function () use ($settlement, $rekening, $date, $user) {
            $locked = Settlement::withoutGlobalScope(BranchScope::class)->whereKey($settlement->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted() || $locked->giro_status !== GiroStatus::Pending) {
                throw new AturanBisnisException("Giro {$locked->giro_number} tidak berstatus belum cair.");
            }
            if (! $rekening->is_active) {
                throw new AturanBisnisException("Rekening {$rekening->name} tidak aktif.");
            }
            if ($date < $locked->settlement_date->toDateString()) {
                throw ValidationException::withMessages(['tanggal' => 'Tanggal cair tidak boleh sebelum tanggal pelunasan.']);
            }

            $locked->forceFill(['giro_status' => GiroStatus::Cleared, 'giro_cleared_on' => $date, 'giro_cash_bank_account_id' => $rekening->getKey()])->save();

            activity($locked->type->isReceivable() ? 'penjualan' : 'pembelian')->performedOn($locked)->causedBy($user)->event('giro_cleared')
                ->withProperties(['rekening' => $rekening->name, 'tanggal' => $date])
                ->log("Giro {$locked->giro_number} ({$locked->number}) cair ke {$rekening->name}");

            GiroCleared::dispatch($locked, $user);

            return $locked;
        });
    }

    /** Giro/cek ditolak bank: pelunasannya dibatalkan, sisa faktur kembali. */
    public function bounceGiro(Settlement $settlement, User $user, string $reason): Settlement
    {
        if ($settlement->giro_status !== GiroStatus::Pending) {
            throw new AturanBisnisException("Giro {$settlement->giro_number} tidak berstatus belum cair.");
        }

        return $this->cancel($settlement, $user, "Giro ditolak: {$reason}", bounced: true);
    }

    /** Saldo uang muka pelanggan/supplier (sen): ditambah kelebihan pelunasan, dikurangi yang dipakai. */
    public function depositBalance(bool $receivable, int $partyId): int
    {
        $sum = Settlement::query()->withoutGlobalScope(BranchScope::class)
            ->where($receivable ? 'customer_id' : 'supplier_id', $partyId)
            ->ofTypes($receivable ? SettlementType::Receipt : SettlementType::Payment)
            ->withStatus(DocumentStatus::Posted)
            ->selectRaw('COALESCE(SUM(deposit_added), 0) as added, COALESCE(SUM(deposit_applied), 0) as applied')
            ->first();

        return Uang::sen($sum->added ?? 0) - Uang::sen($sum->applied ?? 0);
    }

    /**
     * Alokasi dari formulir: "sale:12" => "150000". Nilai kosong/nol dilewati. Urut tetap (kunci).
     *
     * @param  array<string, mixed>  $input
     * @return list<array{type: string, id: int, sen: int}>
     */
    private function allocations(array $input): array
    {
        $rows = [];
        foreach ($input as $key => $value) {
            $sen = Uang::sen($value ?? 0);
            if ($sen === 0) {
                continue;
            }
            if ($sen < 0 || ! preg_match('/^(sale|purchase|invoice):(\d+)$/', (string) $key, $m)) {
                throw ValidationException::withMessages(['allocations' => 'Nilai alokasi tidak sah.']);
            }
            $rows[] = ['type' => $m[1], 'id' => (int) $m[2], 'sen' => $sen];
        }

        usort($rows, fn ($a, $b) => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);

        return $rows;
    }
}
