<?php

namespace App\Services\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\OpeningBalanceKind;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\OpeningBalance;
use App\Models\Scopes\BranchScope;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Receivables\InvoiceService;
use App\Support\CurrentBranch;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saldo awal pembukuan per cabang.
 *
 *   saldo akun        : akun apa saja kecuali Persediaan, Piutang usaha, dan Hutang usaha
 *                       (ketiganya punya bagian sendiri agar rinciannya per gudang/pihak tersimpan)
 *   persediaan        : nilai per gudang cabang ini → D Persediaan
 *   piutang pelanggan : per pelanggan (no. faktur lama, jatuh tempo opsional) → D Piutang usaha
 *   hutang supplier   : per supplier → K Hutang usaha
 *
 * Posting membuat satu jurnal bertanggal balance_date; selisih debit-kredit masuk Ekuitas Saldo
 * Awal. Saldo awal posted tidak diubah: batalkan (jurnalnya dibalik di tanggal yang sama) lalu buat
 * yang baru. Nilai persediaan di sini tidak mengubah jumlah stok (stok diatur di Inventori).
 * Setiap baris piutang/hutang menjadi faktur saldo awal (Invoice kind opening) yang bisa dilunasi;
 * saldo awal yang fakturnya sudah dilunasi tidak bisa dibatalkan.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountMappingService $mapping,
        private readonly CurrentBranch $branches,
    ) {}

    /**
     * Simpan draf baru atau ganti isi draf.
     *
     * @param  array<string, mixed>  $data  balance_date, description, accounts[], inventory[], receivables[], payables[]
     */
    public function saveDraft(array $data, User $user, ?OpeningBalance $draft = null): OpeningBalance
    {
        $date = CarbonImmutable::parse($data['balance_date'])->toDateString();
        app(FiscalPeriodService::class)->ensureOpen($date);
        $branchId = $draft?->branch_id ?? $this->branches->forNewDocument()->getKey();
        $rows = $this->rows($data, $branchId);

        return DB::transaction(function () use ($data, $date, $rows, $user, $draft, $branchId) {
            $document = $draft ? $this->lock($draft) : new OpeningBalance;

            if ($draft && ! $document->isEditable()) {
                throw new AturanBisnisException("Saldo awal {$document->number} berstatus {$document->documentStatus()->label()} dan tidak bisa diubah.");
            }

            $document->fill(['balance_date' => $date, 'description' => $data['description'] ?? null]);
            $document->forceFill(['total' => Uang::keDesimal($this->total($rows))]);

            if (! $document->exists) {
                $document->forceFill(['status' => DocumentStatus::Draft, 'branch_id' => $branchId]);
                $document->assignDocumentNumber();
            }

            $document->save();
            $document->lines()->delete();
            foreach ($rows as $row) {
                $document->lines()->create([
                    ...$row,
                    'debit' => Uang::keDesimal($row['debit']),
                    'credit' => Uang::keDesimal($row['credit']),
                ]);
            }

            activity('akuntansi')->performedOn($document)->causedBy($user)->event($draft ? 'draft_updated' : 'draft_created')
                ->withProperties(['total' => $document->total, 'baris' => count($rows)])
                ->log($draft ? "Draf saldo awal {$document->number} diubah" : "Draf saldo awal {$document->number} dibuat");

            return $document->load('lines');
        });
    }

    /** Posting: buat jurnal saldo awal (selisih ke Ekuitas Saldo Awal). */
    public function post(OpeningBalance $document, User $user): OpeningBalance
    {
        return DB::transaction(function () use ($document, $user) {
            $locked = $this->lock($document);

            if ($locked->documentStatus() !== DocumentStatus::Draft) {
                throw new AturanBisnisException("Saldo awal {$locked->number} berstatus {$locked->documentStatus()->label()} dan tidak bisa diposting.");
            }

            $locked->load(['lines.warehouse', 'lines.customer', 'lines.supplier']);
            $lines = $this->journalLines($locked);

            $this->journals->post(
                $locked->balance_date,
                "Saldo awal {$locked->number}".($locked->description ? " · {$locked->description}" : ''),
                $lines->all(),
                $user,
                $locked,
                'posted',
                attributes: ['branch_id' => $locked->branch_id],
            );
            $locked->moveToStatus(DocumentStatus::Posted, $user);
            // Piutang/hutang per pihak menjadi faktur saldo awal agar bisa dilunasi dan masuk umur piutang/hutang.
            app(InvoiceService::class)->createOpeningInvoices($locked, $user);

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('posted')
                ->withProperties(['total' => $locked->total])
                ->log("Saldo awal {$locked->number} diposting");

            return $locked;
        });
    }

    /** Batalkan draf, atau saldo awal posted (jurnalnya dibalik di tanggal saldo awal). */
    public function cancel(OpeningBalance $document, User $user, string $reason): OpeningBalance
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($document, $user, $reason) {
            $locked = $this->lock($document);
            $status = $locked->documentStatus();

            if ($status === DocumentStatus::Cancelled) {
                throw new AturanBisnisException("Saldo awal {$locked->number} sudah dibatalkan.");
            }

            if ($status === DocumentStatus::Posted) {
                app(InvoiceService::class)->cancelOpeningInvoices($locked, $user, $reason);
                $journal = $this->journals->find($locked, 'posted');
                if ($journal !== null && ! $journal->reversal()->exists()) {
                    $this->journals->reverse($journal, "Pembatalan saldo awal {$locked->number}: {$reason}", $user, $locked, 'voided', $locked->balance_date);
                }
            }

            $locked->moveToStatus(DocumentStatus::Cancelled, $user, mb_substr($reason, 0, 255));

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('cancelled')
                ->withProperties(['alasan' => $reason, 'status_sebelumnya' => $status->label()])
                ->log("Saldo awal {$locked->number} dibatalkan");

            return $locked;
        });
    }

    public function deleteDraft(OpeningBalance $document, User $user): void
    {
        DB::transaction(function () use ($document, $user) {
            $locked = $this->lock($document);
            $locked->delete(); // HasDocumentStatus menolak selain draf

            activity('akuntansi')->causedBy($user)->event('deleted')
                ->withProperties(['nomor' => $locked->number])
                ->log("Draf saldo awal {$locked->number} dihapus");
        });
    }

    /**
     * Nilai persediaan saat ini per gudang (jumlah stok x harga pokok), untuk tombol
     * "Isi dari stok saat ini".
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @return array<int, string> id gudang => nilai Rupiah
     */
    public function currentInventoryValues(Collection $warehouses): array
    {
        $values = DB::table('warehouse_stocks as ws')
            ->join('products as p', 'p.id', '=', 'ws.product_id')
            ->whereIn('ws.warehouse_id', $warehouses->modelKeys() ?: [0])
            ->where('ws.quantity', '>', 0)
            ->get(['ws.warehouse_id', 'ws.quantity', 'p.purchase_price']);

        $result = [];
        foreach ($values as $row) {
            $result[$row->warehouse_id] = ($result[$row->warehouse_id] ?? 0) + Uang::kali(Uang::milli($row->quantity), Uang::sen($row->purchase_price));
        }

        return array_map(fn (int $sen) => Uang::keDesimal($sen), $result);
    }

    /** Akun yang tidak boleh dipakai di bagian "saldo akun" (punya bagian sendiri). @return array<int, string> */
    public function controlAccounts(): array
    {
        $result = [];
        foreach (['inventory' => 'Persediaan', 'receivable' => 'Piutang pelanggan', 'payable' => 'Hutang supplier'] as $key => $section) {
            if ($account = $this->mapping->account($key)) {
                $result[$account->id] = $section;
            }
        }

        return $result;
    }

    /** Baris jurnal saldo awal, termasuk penyeimbang ke Ekuitas Saldo Awal. */
    private function journalLines(OpeningBalance $document): JournalLines
    {
        $lines = new JournalLines;
        $accounts = Account::withTrashed()->whereIn('id', $document->lines->pluck('account_id')->filter())->get()->keyBy('id');

        foreach ($document->lines as $line) {
            $debit = Uang::sen($line->debit);
            $credit = Uang::sen($line->credit);
            $reference = $line->reference ? " · {$line->reference}" : '';

            match ($line->kind) {
                OpeningBalanceKind::Account => $lines
                    ->debit($accounts[$line->account_id], $debit, $line->description ?: 'Saldo awal')
                    ->credit($accounts[$line->account_id], $credit, $line->description ?: 'Saldo awal'),
                OpeningBalanceKind::Inventory => $lines->debit($this->mapping->require('inventory'), $debit, "Persediaan {$line->warehouse?->name}"),
                OpeningBalanceKind::Receivable => $lines->debit($this->mapping->require('receivable'), $debit, "Piutang {$line->customer?->name}{$reference}"),
                OpeningBalanceKind::Payable => $lines->credit($this->mapping->require('payable'), $credit, "Hutang {$line->supplier?->name}{$reference}"),
            };
        }

        $difference = $document->lines->sum(fn ($l) => Uang::sen($l->debit)) - $document->lines->sum(fn ($l) => Uang::sen($l->credit));
        if ($difference > 0) {
            $lines->credit($this->mapping->require('opening_equity'), $difference, 'Penyeimbang saldo awal');
        } elseif ($difference < 0) {
            $lines->debit($this->mapping->require('opening_equity'), -$difference, 'Penyeimbang saldo awal');
        }

        return $lines;
    }

    /**
     * Baris yang lolos aturan (nilai dalam sen). Semua kesalahan dikumpulkan sekaligus.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{kind: OpeningBalanceKind, account_id: ?int, warehouse_id: ?int, customer_id: ?int, supplier_id: ?int, reference: ?string, due_date: ?string, description: ?string, debit: int, credit: int}>
     */
    private function rows(array $data, int $branchId): array
    {
        $errors = [];
        $rows = [];
        $blank = ['account_id' => null, 'warehouse_id' => null, 'customer_id' => null, 'supplier_id' => null, 'reference' => null, 'due_date' => null, 'description' => null];
        $control = $this->controlAccounts();

        $accountIds = array_map(fn ($r) => (int) ($r['account_id'] ?? 0), $data['accounts'] ?? []);
        $accounts = Account::query()->whereIn('id', $accountIds ?: [0])->get()->keyBy('id');
        foreach (array_values($data['accounts'] ?? []) as $i => $row) {
            $debit = Uang::sen($row['debit'] ?? 0);
            $credit = Uang::sen($row['credit'] ?? 0);
            $account = $accounts[(int) ($row['account_id'] ?? 0)] ?? null;

            if ($debit === 0 && $credit === 0 && $account === null) {
                continue;
            }
            if ($account === null) {
                $errors["accounts.{$i}.account_id"] = 'Pilih akun untuk baris ini.';
            } elseif (! $account->isPostable()) {
                $errors["accounts.{$i}.account_id"] = "Akun {$account->label()} tidak aktif atau berupa akun induk.";
            } elseif (isset($control[$account->id])) {
                $errors["accounts.{$i}.account_id"] = "Saldo {$account->label()} diisi di bagian {$control[$account->id]}, agar rinciannya tersimpan.";
            } elseif ($debit > 0 && $credit > 0) {
                $errors["accounts.{$i}.debit"] = 'Isi debit atau kredit saja, tidak boleh keduanya.';
            } elseif ($debit <= 0 && $credit <= 0) {
                $errors["accounts.{$i}.debit"] = 'Isi nilai debit atau kredit.';
            } else {
                $rows[] = ['kind' => OpeningBalanceKind::Account, 'account_id' => $account->id, 'description' => $this->text($row['description'] ?? null, 255), 'debit' => $debit, 'credit' => $credit] + $blank;
            }
        }

        $warehouses = Warehouse::withTrashed()->whereIn('id', array_map(fn ($r) => (int) ($r['warehouse_id'] ?? 0), $data['inventory'] ?? []) ?: [0])->get()->keyBy('id');
        $seen = [];
        foreach (array_values($data['inventory'] ?? []) as $i => $row) {
            $amount = Uang::sen($row['amount'] ?? 0);
            $warehouse = $warehouses[(int) ($row['warehouse_id'] ?? 0)] ?? null;

            if ($amount === 0 && $warehouse === null) {
                continue;
            }
            if ($warehouse === null || (int) $warehouse->branch_id !== $branchId) {
                $errors["inventory.{$i}.warehouse_id"] = 'Pilih gudang di cabang saldo awal ini.';
            } elseif (isset($seen[$warehouse->id])) {
                $errors["inventory.{$i}.warehouse_id"] = "Gudang {$warehouse->name} muncul lebih dari sekali.";
            } elseif ($amount <= 0) {
                $errors["inventory.{$i}.amount"] = 'Nilai persediaan harus lebih dari nol.';
            } else {
                $seen[$warehouse->id] = true;
                $rows[] = ['kind' => OpeningBalanceKind::Inventory, 'warehouse_id' => $warehouse->id, 'debit' => $amount, 'credit' => 0] + $blank;
            }
        }

        foreach (['receivables' => [OpeningBalanceKind::Receivable, 'customer_id', Customer::class, 'pelanggan'], 'payables' => [OpeningBalanceKind::Payable, 'supplier_id', Supplier::class, 'supplier']] as $section => [$kind, $column, $model, $party]) {
            $parties = $model::query()->whereIn('id', array_map(fn ($r) => (int) ($r[$column] ?? 0), $data[$section] ?? []) ?: [0])->get()->keyBy('id');

            foreach (array_values($data[$section] ?? []) as $i => $row) {
                $amount = Uang::sen($row['amount'] ?? 0);
                $found = $parties[(int) ($row[$column] ?? 0)] ?? null;

                if ($amount === 0 && $found === null) {
                    continue;
                }
                if ($found === null) {
                    $errors["{$section}.{$i}.{$column}"] = "Pilih {$party} untuk baris ini.";
                } elseif ($amount <= 0) {
                    $errors["{$section}.{$i}.amount"] = 'Nilai harus lebih dari nol.';
                } else {
                    $rows[] = [
                        'kind' => $kind,
                        $column => $found->id,
                        'reference' => $this->text($row['reference'] ?? null, 50),
                        'due_date' => blank($row['due_date'] ?? null) ? null : CarbonImmutable::parse($row['due_date'])->toDateString(),
                        'debit' => $kind === OpeningBalanceKind::Receivable ? $amount : 0,
                        'credit' => $kind === OpeningBalanceKind::Payable ? $amount : 0,
                    ] + $blank;
                }
            }
        }

        if ($errors === [] && $rows === []) {
            $errors['accounts'] = 'Saldo awal masih kosong. Isi minimal satu baris di salah satu bagian.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /** Total jurnal (sisi debit setelah penyeimbang), sen. @param list<array{debit: int, credit: int}> $rows */
    private function total(array $rows): int
    {
        return max(array_sum(array_column($rows, 'debit')), array_sum(array_column($rows, 'credit')));
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function lock(OpeningBalance $document): OpeningBalance
    {
        return OpeningBalance::withoutGlobalScope(BranchScope::class)->whereKey($document->getKey())->lockForUpdate()->firstOrFail();
    }
}
