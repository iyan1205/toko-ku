<?php

namespace App\Services\Receivables;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Enums\OpeningBalanceKind;
use App\Enums\PaymentStatus;
use App\Events\InvoiceCancelled;
use App\Events\InvoicePosted;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OpeningBalance;
use App\Models\Scopes\BranchScope;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\AccountMappingService;
use App\Services\Accounting\FiscalPeriodService;
use App\Services\PaymentService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Faktur penjualan dan tagihan pembelian manual (non-stok), serta faktur saldo awal.
 *
 *   faktur penjualan : D Piutang usaha | K akun pendapatan per baris, PPN keluaran
 *   tagihan pembelian: D akun beban/aset per baris, PPN masukan | K Hutang usaha
 *
 * Draf bisa diubah/dihapus; posting membuat jurnal (listener, InvoicePosted) dan menambah saldo
 * piutang pelanggan. Faktur posted dibatalkan bila belum ada pembayaran/nota (jurnal dibalik).
 * Faktur saldo awal (kind opening) dibuat dan dibatalkan bersama saldo awalnya, tanpa jurnal sendiri.
 */
class InvoiceService
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * @param  array<string, mixed>  $data  customer_id|supplier_id, invoice_date, due_date, reference, description, tax_rate, lines[]
     */
    public function saveDraft(InvoiceType $type, array $data, User $user, ?Invoice $draft = null): Invoice
    {
        $date = CarbonImmutable::parse($data['invoice_date'])->toDateString();
        app(FiscalPeriodService::class)->ensureOpen($date);
        [$party, $rows, $subtotal, $tax, $rate] = $this->validated($type, $data);

        return DB::transaction(function () use ($type, $data, $date, $party, $rows, $subtotal, $tax, $rate, $user, $draft) {
            $invoice = $draft ? $this->lock($draft) : new Invoice(['type' => $type]);

            if ($draft && ! $invoice->isEditable()) {
                throw new AturanBisnisException("{$invoice->documentLabel()} berstatus {$invoice->documentStatus()->label()} dan tidak bisa diubah.");
            }

            $invoice->fill([
                'customer_id' => $type === InvoiceType::Sales ? $party->getKey() : null,
                'supplier_id' => $type === InvoiceType::Purchase ? $party->getKey() : null,
                'invoice_date' => $date,
                'due_date' => $data['due_date'] ?? CarbonImmutable::parse($date)->addDays((int) ($party->payment_terms_days ?? setting('receivables.terms_days', 30)))->toDateString(),
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
            ]);
            $invoice->forceFill([
                'subtotal' => Uang::keDesimal($subtotal),
                'tax_rate' => $rate,
                'tax' => Uang::keDesimal($tax),
                'total' => Uang::keDesimal($subtotal + $tax),
                'balance' => Uang::keDesimal($subtotal + $tax),
                'payment_status' => PaymentStatus::Unpaid,
            ]);

            if (! $invoice->exists) {
                $invoice->forceFill(['status' => DocumentStatus::Draft, 'kind' => 'manual']);
                $invoice->assignDocumentNumber();
            }

            $invoice->save();
            $invoice->lines()->delete();
            foreach ($rows as $row) {
                $invoice->lines()->create($row);
            }

            activity($type === InvoiceType::Sales ? 'penjualan' : 'pembelian')->performedOn($invoice)->causedBy($user)->event($draft ? 'draft_updated' : 'draft_created')
                ->withProperties(['total' => $invoice->total, 'pihak' => $party->name])
                ->log(($draft ? 'Draf diubah: ' : 'Draf dibuat: ')."{$type->label()} {$invoice->number}");

            return $invoice->load('lines');
        });
    }

    /** Posting draf: masuk piutang/hutang dan dijurnal. */
    public function post(Invoice $invoice, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $user) {
            $locked = $this->lock($invoice);

            if ($locked->documentStatus() !== DocumentStatus::Draft) {
                throw new AturanBisnisException("{$locked->documentLabel()} berstatus {$locked->documentStatus()->label()} dan tidak bisa diposting.");
            }
            app(FiscalPeriodService::class)->ensureOpen($locked->invoice_date);

            $locked->moveToStatus(DocumentStatus::Posted, $user);
            if ($locked->isSales()) {
                $this->payments->ubahPiutangPelanggan($locked->customer_id, Uang::sen($locked->total));
            }

            activity($locked->isSales() ? 'penjualan' : 'pembelian')->performedOn($locked)->causedBy($user)->event('posted')
                ->withProperties(['total' => $locked->total])
                ->log("{$locked->type->label()} {$locked->number} diposting");

            InvoicePosted::dispatch($locked);

            return $locked;
        });
    }

    /** Batalkan draf, atau faktur posted yang belum dibayar/dikurangi nota (jurnal dibalik hari ini). */
    public function cancel(Invoice $invoice, User $user, string $reason): Invoice
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($invoice, $user, $reason) {
            $locked = $this->lock($invoice);
            $posted = $locked->isPosted();

            if ($locked->isOpening()) {
                throw new AturanBisnisException("{$locked->number} berasal dari saldo awal. Batalkan saldo awalnya.");
            }
            if ($locked->documentStatus() === DocumentStatus::Cancelled) {
                throw new AturanBisnisException("{$locked->documentLabel()} sudah dibatalkan.");
            }
            if (Uang::sen($locked->paid_amount) > 0 || Uang::sen($locked->credited_amount) > 0) {
                throw new AturanBisnisException("{$locked->documentLabel()} sudah dibayar atau dikurangi nota. Batalkan pelunasannya dulu.");
            }
            if ($posted) {
                app(FiscalPeriodService::class)->ensureOpen(now());
                if ($locked->isSales()) {
                    $this->payments->ubahPiutangPelanggan($locked->customer_id, -Uang::sen($locked->balance));
                }
            }

            $locked->moveToStatus(DocumentStatus::Cancelled, $user, mb_substr($reason, 0, 255));

            activity($locked->isSales() ? 'penjualan' : 'pembelian')->performedOn($locked)->causedBy($user)->event('cancelled')
                ->withProperties(['alasan' => $reason])
                ->log("{$locked->type->label()} {$locked->number} dibatalkan");

            if ($posted) {
                InvoiceCancelled::dispatch($locked, $user);
            }

            return $locked;
        });
    }

    public function deleteDraft(Invoice $invoice): void
    {
        DB::transaction(fn () => $this->lock($invoice)->delete());
    }

    /**
     * Faktur saldo awal untuk setiap baris piutang/hutang (dipanggil saat saldo awal diposting).
     * Jurnalnya sudah ada di jurnal saldo awal.
     */
    public function createOpeningInvoices(OpeningBalance $opening, User $user): void
    {
        foreach ($opening->lines as $line) {
            if (! in_array($line->kind, [OpeningBalanceKind::Receivable, OpeningBalanceKind::Payable], true)) {
                continue;
            }

            $sales = $line->kind === OpeningBalanceKind::Receivable;
            $amount = $sales ? $line->debit : $line->credit;
            $invoice = new Invoice([
                'type' => $sales ? InvoiceType::Sales : InvoiceType::Purchase,
                'customer_id' => $sales ? $line->customer_id : null,
                'supplier_id' => $sales ? null : $line->supplier_id,
                'invoice_date' => $opening->balance_date,
                'due_date' => $line->due_date,
                'reference' => $line->reference,
                'description' => "Saldo awal {$opening->number}",
            ]);
            $invoice->forceFill([
                'kind' => 'opening',
                'branch_id' => $opening->branch_id,
                'opening_balance_id' => $opening->getKey(),
                'subtotal' => $amount, 'tax' => 0, 'total' => $amount, 'balance' => $amount,
                'payment_status' => PaymentStatus::Unpaid,
                'status' => DocumentStatus::Posted,
                'posted_at' => now(),
                'posted_by' => $user->getKey(),
            ]);
            $invoice->assignDocumentNumber();
            $invoice->save();

            if ($sales) {
                $this->payments->ubahPiutangPelanggan($line->customer_id, Uang::sen($amount));
            }
        }
    }

    /** Batalkan faktur saldo awal (saldo awal dibatalkan). Ditolak bila sudah ada pelunasan. */
    public function cancelOpeningInvoices(OpeningBalance $opening, User $user, string $reason): void
    {
        $invoices = Invoice::withoutGlobalScope(BranchScope::class)->where('opening_balance_id', $opening->getKey())
            ->withStatus(DocumentStatus::Posted)->lockForUpdate()->get();

        foreach ($invoices as $invoice) {
            if (Uang::sen($invoice->paid_amount) > 0 || Uang::sen($invoice->credited_amount) > 0) {
                throw new AturanBisnisException("Piutang/hutang saldo awal {$invoice->number} sudah dilunasi sebagian. Batalkan pelunasannya dulu.");
            }
        }

        foreach ($invoices as $invoice) {
            if ($invoice->isSales()) {
                $this->payments->ubahPiutangPelanggan($invoice->customer_id, -Uang::sen($invoice->balance));
            }
            $invoice->moveToStatus(DocumentStatus::Cancelled, $user, mb_substr("Saldo awal dibatalkan: {$reason}", 0, 255));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Customer|Supplier, 1: list<array<string, mixed>>, 2: int, 3: int, 4: string}
     */
    private function validated(InvoiceType $type, array $data): array
    {
        $errors = [];
        $party = $type === InvoiceType::Sales
            ? Customer::query()->where('is_default', false)->find((int) ($data['customer_id'] ?? 0))
            : Supplier::query()->find((int) ($data['supplier_id'] ?? 0));
        if ($party === null) {
            $errors[$type === InvoiceType::Sales ? 'customer_id' : 'supplier_id'] = $type === InvoiceType::Sales ? 'Pilih pelanggan (bukan pelanggan Umum).' : 'Pilih supplier.';
        }

        $lines = array_values($data['lines'] ?? []);
        $accounts = Account::query()->whereIn('id', array_map(fn ($l) => (int) ($l['account_id'] ?? 0), $lines) ?: [0])->get()->keyBy('id');
        $costCenters = CostCenter::query()->whereIn('id', array_map(fn ($l) => (int) ($l['cost_center_id'] ?? 0), $lines) ?: [0])->get()->keyBy('id');
        $control = $this->controlAccountIds();
        $rows = [];
        $subtotal = 0;

        foreach ($lines as $i => $line) {
            $description = trim((string) ($line['description'] ?? ''));
            $milli = Uang::milli($line['quantity'] ?? 1);
            $price = Uang::sen($line['unit_price'] ?? 0);
            if ($description === '' && $price === 0) {
                continue;
            }

            $account = $accounts[(int) ($line['account_id'] ?? 0)] ?? null;
            $costCenter = ($line['cost_center_id'] ?? null) ? ($costCenters[(int) $line['cost_center_id']] ?? null) : null;
            if ($description === '') {
                $errors["lines.{$i}.description"] = 'Isi keterangan baris.';
            } elseif ($account === null || ! $account->isPostable()) {
                $errors["lines.{$i}.account_id"] = 'Pilih akun yang aktif untuk baris ini.';
            } elseif (in_array($account->id, $control, true)) {
                $errors["lines.{$i}.account_id"] = "Akun {$account->label()} adalah akun piutang/hutang; pilih akun pendapatan atau beban.";
            } elseif ($milli <= 0 || $price <= 0) {
                $errors["lines.{$i}.unit_price"] = 'Jumlah dan harga harus lebih dari nol.';
            } elseif (($line['cost_center_id'] ?? null) && ($costCenter === null || ! $costCenter->is_active)) {
                $errors["lines.{$i}.cost_center_id"] = 'Cost center tidak aktif.';
            } else {
                $amount = Uang::kali($milli, $price);
                $subtotal += $amount;
                $rows[] = [
                    'account_id' => $account->id,
                    'cost_center_id' => $costCenter?->id,
                    'description' => mb_substr($description, 0, 255),
                    'quantity' => Uang::milliKeDesimal($milli),
                    'unit_price' => Uang::keDesimal($price),
                    'amount' => Uang::keDesimal($amount),
                ];
            }
        }

        if ($errors === [] && $rows === []) {
            $errors['lines'] = 'Isi minimal satu baris.';
        }

        $rate = round((float) ($data['tax_rate'] ?? 0), 2);
        if ($rate < 0 || $rate > 100) {
            $errors['tax_rate'] = 'Tarif pajak 0–100%.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $tax = (int) round($subtotal * $rate / 100);

        return [$party, $rows, $subtotal, $tax, number_format($rate, 2, '.', '')];
    }

    /** @return list<int> akun piutang/hutang/uang muka yang tidak boleh dipakai di baris faktur */
    private function controlAccountIds(): array
    {
        $mapping = app(AccountMappingService::class);

        return array_values(array_filter(array_map(fn (string $key) => $mapping->account($key)?->getKey(), ['receivable', 'payable', 'customer_deposit', 'supplier_deposit'])));
    }

    private function lock(Invoice $invoice): Invoice
    {
        return Invoice::withoutGlobalScope(BranchScope::class)->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
    }
}
