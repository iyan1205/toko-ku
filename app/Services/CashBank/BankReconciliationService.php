<?php

namespace App\Services\CashBank;

use App\Enums\CashTransactionType;
use App\Enums\DocumentStatus;
use App\Exceptions\AturanBisnisException;
use App\Imports\RowsReader;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\CashBankAccount;
use App\Models\CashTransaction;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Rekonsiliasi bank: impor rekening koran (CSV/Excel), cocokkan setiap mutasi dengan baris jurnal
 * pada akun rekening itu, lalu laporan rekonsiliasi.
 *
 *   otomatis : nominal dan arah (masuk/keluar) sama persis, tanggal selisih paling banyak
 *              setting bank_reconciliation.tolerance_days hari (bawaan 3); dipilih yang tanggalnya terdekat.
 *   manual   : pilih baris jurnal dengan arah yang sama; atau catat mutasi yang belum ada di buku
 *              (misal biaya admin bank) sebagai transaksi kas yang langsung dicocokkan.
 *
 * Satu baris jurnal hanya bisa dicocokkan dengan satu mutasi. Rekening koran yang sudah dikunci
 * (finalized) tidak bisa diubah. Saldo buku dihitung dari semua cabang (rekening koran mencakup
 * seluruh rekening).
 */
class BankReconciliationService
{
    /** Judul kolom yang dikenali (sudah di-slug oleh pembaca berkas). */
    private const COLUMNS = [
        'date' => ['tanggal', 'tgl', 'date', 'tanggal_transaksi', 'tgl_transaksi', 'posting_date'],
        'description' => ['keterangan', 'deskripsi', 'uraian', 'description', 'keterangan_transaksi', 'berita'],
        'reference' => ['referensi', 'ref', 'no_referensi', 'reference', 'nomor'],
        'in' => ['masuk', 'kredit', 'credit', 'cr', 'mutasi_masuk', 'setoran'],
        'out' => ['keluar', 'debit', 'debet', 'db', 'mutasi_keluar', 'penarikan'],
        'amount' => ['jumlah', 'nominal', 'amount', 'mutasi'],
        'balance' => ['saldo', 'balance'],
    ];

    public function __construct(private readonly CashTransactionService $cash) {}

    /**
     * @param  array{period_start: string, period_end: string, closing_balance: mixed, opening_balance?: mixed}  $data
     */
    public function import(CashBankAccount $rekening, UploadedFile $file, array $data, User $user): BankStatement
    {
        $start = CarbonImmutable::parse($data['period_start'])->toDateString();
        $end = CarbonImmutable::parse($data['period_end'])->toDateString();
        $rows = $this->parse($file, $start, $end);

        return DB::transaction(function () use ($rekening, $file, $data, $start, $end, $rows, $user) {
            $statement = new BankStatement([
                'cash_bank_account_id' => $rekening->getKey(),
                'period_start' => $start,
                'period_end' => $end,
                'opening_balance' => ($data['opening_balance'] ?? null) === null || $data['opening_balance'] === '' ? null : $data['opening_balance'],
                'closing_balance' => $data['closing_balance'],
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            ]);
            $statement->forceFill(['status' => BankStatement::OPEN])->save();

            foreach ($rows as $row) {
                $statement->lines()->create($row);
            }

            activity('akuntansi')->performedOn($statement)->causedBy($user)->event('imported')
                ->withProperties(['rekening' => $rekening->name, 'baris' => count($rows)])
                ->log("Rekening koran {$rekening->name} {$start} s.d. {$end} diimpor (".count($rows).' mutasi)');

            return $statement;
        });
    }

    /** Cocokkan otomatis semua mutasi yang belum cocok. Mengembalikan jumlah yang berhasil. */
    public function autoMatch(BankStatement $statement, User $user): int
    {
        $this->ensureOpen($statement);
        $tolerance = (int) setting('bank_reconciliation.tolerance_days', 3);
        $matched = 0;

        DB::transaction(function () use ($statement, $tolerance, $user, &$matched) {
            foreach ($statement->lines()->whereNull('journal_line_id')->get() as $line) {
                $candidate = $this->candidates($line, $tolerance, exact: true)->first();
                if ($candidate !== null) {
                    $this->attach($line, $candidate->id, 'auto', $user);
                    $matched++;
                }
            }
        });

        return $matched;
    }

    /**
     * Baris jurnal yang bisa dicocokkan dengan mutasi ini (belum dipakai mutasi lain, arah sama),
     * tanggal terdekat dulu.
     *
     * @return Collection<int, JournalLine>
     */
    public function candidates(BankStatementLine $line, int $days = 31, bool $exact = false): Collection
    {
        $statement = $line->statement;
        $accountId = $statement->cashBankAccount->account_id;
        $sen = Uang::sen($line->amount);
        $date = CarbonImmutable::parse($line->line_date);

        return $this->bookLines($accountId)
            ->where($sen > 0 ? 'journal_lines.debit' : 'journal_lines.credit', $exact ? '=' : '>', $exact ? Uang::keDesimal(abs($sen)) : 0)
            ->where('journals.journal_date', '>=', $date->subDays($days)->format('Y-m-d 00:00:00'))
            ->where('journals.journal_date', '<', $date->addDays($days + 1)->format('Y-m-d 00:00:00'))
            ->whereNotIn('journal_lines.id', BankStatementLine::query()->whereNotNull('journal_line_id')->select('journal_line_id'))
            ->get()
            ->sortBy(fn (JournalLine $l) => [abs($date->diffInDays(CarbonImmutable::parse($l->journal_date), false)), $l->id])
            ->values();
    }

    public function match(BankStatementLine $line, int $journalLineId, User $user): void
    {
        $this->ensureOpen($line->statement);

        DB::transaction(function () use ($line, $journalLineId, $user) {
            $candidate = $this->candidates($line)->firstWhere('id', $journalLineId);
            if ($candidate === null) {
                throw new AturanBisnisException('Baris jurnal itu tidak bisa dicocokkan: arahnya berbeda, sudah dipakai mutasi lain, atau tanggalnya terlalu jauh.');
            }
            $this->attach($line, $journalLineId, 'manual', $user);
        });
    }

    public function unmatch(BankStatementLine $line): void
    {
        $this->ensureOpen($line->statement);
        $line->forceFill(['journal_line_id' => null, 'match_type' => null, 'matched_at' => null, 'matched_by' => null])->save();
    }

    /**
     * Mutasi bank yang belum dicatat di buku (misal biaya admin, bunga): dicatat sebagai
     * penerimaan/pengeluaran kas dengan akun lawan, lalu langsung dicocokkan.
     */
    public function record(BankStatementLine $line, int $accountId, ?string $description, User $user): CashTransaction
    {
        $this->ensureOpen($line->statement);
        if ($line->isMatched()) {
            throw new AturanBisnisException('Mutasi ini sudah dicocokkan.');
        }

        return DB::transaction(function () use ($line, $accountId, $description, $user) {
            $sen = Uang::sen($line->amount);
            $transaction = $this->cash->createPosted($sen > 0 ? CashTransactionType::In : CashTransactionType::Out, [
                'transaction_date' => $line->line_date->toDateString(),
                'cash_bank_account_id' => $line->statement->cash_bank_account_id,
                'reference' => $line->reference,
                'description' => $description ?: mb_substr($line->description, 0, 255),
                'lines' => [['account_id' => $accountId, 'amount' => Uang::keDesimal(abs($sen)), 'description' => $line->description]],
            ], $user);

            if (! $transaction->isPosted()) {
                throw new AturanBisnisException("{$transaction->number} perlu persetujuan dulu. Cocokkan mutasi ini setelah transaksinya diposting.");
            }

            $journalLine = JournalLine::query()
                ->whereHas('journal', fn ($q) => $q->withoutGlobalScopes()->where('source_type', $transaction->getMorphClass())->where('source_id', $transaction->getKey()))
                ->where('account_id', $line->statement->cashBankAccount->account_id)
                ->firstOrFail();
            $this->attach($line, $journalLine->id, 'created', $user);

            return $transaction;
        });
    }

    /**
     * Laporan rekonsiliasi (sen).
     *
     * @return array{bank_closing: int, book_closing: int, book_in: int, book_out: int, bank_in: int, bank_out: int, adjusted_bank: int, adjusted_book: int, difference: int, unmatched_book: Collection<int, JournalLine>, unmatched_bank: Collection<int, BankStatementLine>, matched: int, total: int}
     */
    public function report(BankStatement $statement): array
    {
        $accountId = $statement->cashBankAccount->account_id;
        $until = CarbonImmutable::parse($statement->period_end)->addDay()->format('Y-m-d 00:00:00');
        $from = CarbonImmutable::parse($statement->period_start)->format('Y-m-d 00:00:00');

        $book = $this->bookQuery($accountId)->where('journals.journal_date', '<', $until)
            ->toBase()->selectRaw('COALESCE(SUM(journal_lines.debit), 0) as debit, COALESCE(SUM(journal_lines.credit), 0) as credit')->first();
        $bookClosing = Uang::sen($book->debit ?? 0) - Uang::sen($book->credit ?? 0);

        $matchedIds = BankStatementLine::query()->whereNotNull('journal_line_id')->select('journal_line_id');
        $unmatchedBook = $this->bookLines($accountId)
            ->where('journals.journal_date', '>=', $from)->where('journals.journal_date', '<', $until)
            ->whereNotIn('journal_lines.id', $matchedIds)->get();
        $lines = $statement->lines()->get();
        $unmatchedBank = $lines->whereNull('journal_line_id')->values();

        $bookIn = $unmatchedBook->sum(fn ($l) => Uang::sen($l->debit));
        $bookOut = $unmatchedBook->sum(fn ($l) => Uang::sen($l->credit));
        $bankIn = $unmatchedBank->filter(fn ($l) => Uang::sen($l->amount) > 0)->sum(fn ($l) => Uang::sen($l->amount));
        $bankOut = -$unmatchedBank->filter(fn ($l) => Uang::sen($l->amount) < 0)->sum(fn ($l) => Uang::sen($l->amount));
        $bankClosing = Uang::sen($statement->closing_balance);
        $adjustedBank = $bankClosing + $bookIn - $bookOut;
        $adjustedBook = $bookClosing + $bankIn - $bankOut;

        return [
            'bank_closing' => $bankClosing,
            'book_closing' => $bookClosing,
            'book_in' => $bookIn,
            'book_out' => $bookOut,
            'bank_in' => $bankIn,
            'bank_out' => $bankOut,
            'adjusted_bank' => $adjustedBank,
            'adjusted_book' => $adjustedBook,
            'difference' => $adjustedBank - $adjustedBook,
            'unmatched_book' => $unmatchedBook,
            'unmatched_bank' => $unmatchedBank,
            'matched' => $lines->whereNotNull('journal_line_id')->count(),
            'total' => $lines->count(),
        ];
    }

    /** Kunci rekonsiliasi: semua mutasi bank harus sudah dicocokkan atau dicatat. */
    public function finalize(BankStatement $statement, User $user): BankStatement
    {
        $this->ensureOpen($statement);
        $open = $statement->lines()->whereNull('journal_line_id')->count();
        if ($open > 0) {
            throw new AturanBisnisException("Masih ada {$open} mutasi bank yang belum dicocokkan. Cocokkan atau catat sebagai transaksi dulu.");
        }

        $statement->forceFill(['status' => BankStatement::FINALIZED, 'finalized_at' => now(), 'finalized_by' => $user->getKey()])->save();
        activity('akuntansi')->performedOn($statement)->causedBy($user)->event('finalized')
            ->log("Rekonsiliasi {$statement->cashBankAccount->name} {$statement->period_start->toDateString()} s.d. {$statement->period_end->toDateString()} dikunci");

        return $statement;
    }

    public function delete(BankStatement $statement): void
    {
        $this->ensureOpen($statement);
        DB::transaction(function () use ($statement) {
            $statement->lines()->delete();
            $statement->delete();
        });
    }

    /** Baris jurnal posted pada akun rekening, semua cabang (tanpa kolom pilihan). */
    private function bookQuery(int $accountId): Builder
    {
        return JournalLine::query()
            ->join('journals', 'journals.id', '=', 'journal_lines.journal_id')
            ->where('journals.status', DocumentStatus::Posted->value)
            ->where('journal_lines.account_id', $accountId);
    }

    /** Baris jurnal posted pada akun rekening beserta nomor, tanggal, dan keterangan jurnalnya. */
    private function bookLines(int $accountId): Builder
    {
        return $this->bookQuery($accountId)
            ->select('journal_lines.*', 'journals.number as journal_number', 'journals.journal_date', 'journals.description as journal_description')
            ->orderBy('journals.journal_date')->orderBy('journal_lines.id');
    }

    private function attach(BankStatementLine $line, int $journalLineId, string $type, User $user): void
    {
        $line->forceFill(['journal_line_id' => $journalLineId, 'match_type' => $type, 'matched_at' => now(), 'matched_by' => $user->getKey()])->save();
    }

    private function ensureOpen(BankStatement $statement): void
    {
        if ($statement->isFinalized()) {
            throw new AturanBisnisException('Rekonsiliasi ini sudah dikunci dan tidak bisa diubah.');
        }
    }

    /**
     * Baca berkas: semua kesalahan dikumpulkan; berkas ditolak bila ada yang salah.
     *
     * @return list<array{line_date: string, description: string, reference: ?string, amount: string, balance: ?string}>
     */
    private function parse(UploadedFile $file, string $start, string $end): array
    {
        $reader = new RowsReader;
        Excel::import($reader, $file);
        $headings = array_keys($reader->rows->first()?->all() ?? []);
        $column = fn (string $key) => collect(self::COLUMNS[$key])->first(fn ($c) => in_array($c, $headings, true));

        $keys = array_map($column, array_combine(array_keys(self::COLUMNS), array_keys(self::COLUMNS)));
        if ($keys['date'] === null || $keys['description'] === null || ($keys['amount'] === null && $keys['in'] === null && $keys['out'] === null)) {
            throw ValidationException::withMessages(['file' => 'Kolom Tanggal, Keterangan, dan Masuk/Keluar (atau Jumlah) tidak ditemukan di baris judul. Pakai templat rekening koran.']);
        }

        $rows = [];
        $errors = [];
        foreach ($reader->rows->values() as $i => $row) {
            $line = $i + 2;
            $raw = $row->all();
            if (array_filter($raw, fn ($v) => $v !== null && trim((string) $v) !== '') === []) {
                continue;
            }

            $date = $this->date($raw[$keys['date']] ?? null);
            $amount = $keys['amount'] !== null && ($raw[$keys['amount']] ?? null) !== null && $raw[$keys['amount']] !== ''
                ? $this->amount($raw[$keys['amount']])
                : (($keys['in'] ? ($this->amount($raw[$keys['in']] ?? 0) ?? 0) : 0) - ($keys['out'] ? ($this->amount($raw[$keys['out']] ?? 0) ?? 0) : 0));
            $description = trim((string) ($raw[$keys['description']] ?? ''));

            if ($date === null) {
                $errors[] = "Baris {$line}: tanggal tidak terbaca.";
            } elseif ($date < $start || $date > $end) {
                $errors[] = "Baris {$line}: tanggal {$date} di luar periode rekening koran.";
            }
            if ($amount === null || $amount === 0) {
                $errors[] = "Baris {$line}: nilai mutasi kosong atau tidak terbaca.";
            }
            if ($description === '') {
                $errors[] = "Baris {$line}: keterangan kosong.";
            }

            $balance = $keys['balance'] ? $this->amount($raw[$keys['balance']] ?? null) : null;
            $rows[] = [
                'line_date' => $date,
                'description' => mb_substr($description, 0, 255),
                'reference' => $keys['reference'] && filled($raw[$keys['reference']] ?? null) ? mb_substr(trim((string) $raw[$keys['reference']]), 0, 100) : null,
                'amount' => Uang::keDesimal((int) $amount),
                'balance' => $balance === null ? null : Uang::keDesimal($balance),
            ];
        }

        if ($rows === []) {
            $errors[] = 'Berkas tidak berisi mutasi.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 10)]);
        }

        return $rows;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y', 'Y/m/d'] as $format) {
            $parsed = CarbonImmutable::createFromFormat('!'.$format, trim((string) $value));
            if ($parsed !== false && $parsed->format($format) === trim((string) $value)) {
                return $parsed->toDateString();
            }
        }

        return null;
    }

    /** Nilai rupiah dalam sen dari angka atau teks seperti "1.250.000,00", "1,250,000.00", "-50000". */
    private function amount(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (int) round($value * 100);
        }

        $text = preg_replace('/[^0-9,.\-]/', '', (string) $value);
        if ($text === '' || $text === '-') {
            return null;
        }
        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $text = str_replace(['.', ','], ['', '.'], $text); // 1.250.000,50
        } elseif ($lastDot !== false && substr_count($text, '.') > 1) {
            $text = str_replace('.', '', $text); // 1.250.000
        } elseif ($lastDot !== false && strlen($text) - $lastDot - 1 === 3 && $lastComma === false) {
            $text = str_replace('.', '', $text); // 250.000
        } else {
            $text = str_replace(',', '', $text); // 1,250,000.50
        }

        return is_numeric($text) ? (int) round(((float) $text) * 100) : null;
    }
}
