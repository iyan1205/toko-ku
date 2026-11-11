<?php

namespace App\Services\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Journal;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Settings\DocumentNumberService;
use App\Support\CurrentBranch;
use App\Support\Format;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Satu-satunya pintu pembuatan jurnal (manual maupun otomatis).
 *
 * Aturan: minimal dua baris bernilai, setiap baris tepat satu sisi (debit atau kredit), akun
 * aktif dan bukan akun induk, serta total debit = total kredit. Semua dihitung dengan bilangan
 * bulat sen. Jurnal tidak pernah diubah; koreksi memakai reverse() (jurnal pembalik).
 *
 * Jurnal otomatis menyimpan dokumen asal + kejadian (source + event). Kombinasi itu unik:
 * memanggil post() dua kali untuk kejadian yang sama mengembalikan jurnal yang sudah ada.
 *
 * Jurnal manual (createManual): langsung diposting, atau disimpan sebagai draf lalu diajukan
 * bila ada alur persetujuan "journal" yang cocok. Draf diposting dengan postDraft(); draf
 * yang tidak jadi dipakai dibatalkan dengan cancelDraft(). Hanya jurnal posted yang dihitung
 * di saldo akun (lihat AccountsExport).
 *
 * Setiap baris boleh diberi cost center (dimensi laporan). Atribut tambahan jurnal manual
 * ($attributes): auto_reverse_on (dibalik otomatis pada tanggal itu), recurring_journal_id +
 * recurring_period (hasil jurnal berulang), dan branch_id (cabang jurnal; bawaan cabang aktif).
 */
class JournalService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ApprovalService $approvals,
        private readonly CurrentBranch $branches,
    ) {}

    /**
     * @param  list<array{account_id: mixed, debit?: mixed, credit?: mixed, description?: ?string, cost_center_id?: mixed}>  $lines  nilai dalam Rupiah
     * @param  array<string, mixed>  $attributes  lihat docblock kelas
     *
     * @throws ValidationException bila jurnal tidak memenuhi aturan di atas
     */
    public function post(
        DateTimeInterface|string $date,
        string $description,
        array $lines,
        ?User $user,
        ?Model $source = null,
        ?string $event = null,
        ?Journal $reversalOf = null,
        array $attributes = [],
    ): Journal {
        if ($source !== null && ($existing = $this->find($source, (string) $event))) {
            return $existing;
        }

        $rows = $this->validated($lines, strictCostCenters: $reversalOf === null);
        $date = CarbonImmutable::parse($date)->toDateString();
        $attributes = $this->manualAttributes($attributes, $date);
        app(FiscalPeriodService::class)->ensureOpen($date);

        return DB::transaction(function () use ($date, $description, $rows, $user, $source, $event, $reversalOf, $attributes) {
            $journal = new Journal([
                'number' => $this->numbers->next(DocumentType::Journal, $date),
                'journal_date' => $date,
                'description' => mb_substr(trim($description), 0, 255),
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'source_event' => $source ? $event : null,
                'reversal_of_id' => $reversalOf?->getKey(),
                'total' => Uang::keDesimal(array_sum(array_column($rows, 'debit'))),
                'user_id' => $user?->getKey(),
            ]);
            $journal->forceFill(['status' => DocumentStatus::Posted, 'posted_at' => now(), 'posted_by' => $user?->getKey()] + $attributes)->save();
            $this->saveLines($journal, $rows);

            if ($source === null) {
                activity('akuntansi')->performedOn($journal)->causedBy($user)->event($reversalOf ? 'reversed' : 'created')
                    ->withProperties(['total' => $journal->total])
                    ->log($reversalOf ? "Jurnal {$reversalOf->number} dibalik dengan {$journal->number}" : "Jurnal manual {$journal->number} dibuat");
            }

            return $journal->load('lines');
        });
    }

    /**
     * Jurnal pembalik: semua baris ditukar sisinya. Satu jurnal hanya bisa dibalik sekali,
     * dan jurnal pembalik tidak bisa dibalik lagi.
     */
    public function reverse(
        Journal $journal,
        string $description,
        ?User $user,
        ?Model $source = null,
        ?string $event = null,
        DateTimeInterface|string|null $date = null,
    ): Journal {
        return DB::transaction(function () use ($journal, $description, $user, $source, $event, $date) {
            $locked = Journal::withoutGlobalScope(BranchScope::class)->whereKey($journal->getKey())->lockForUpdate()->with(['lines', 'reversal'])->firstOrFail();

            if (! $locked->isPosted()) {
                throw new AturanBisnisException("Jurnal {$locked->number} belum diposting. Draf yang tidak jadi dipakai cukup dibatalkan.");
            }
            if ($locked->reversal_of_id !== null) {
                throw new AturanBisnisException("Jurnal {$locked->number} adalah jurnal pembalik dan tidak bisa dibalik lagi.");
            }
            if ($locked->reversal !== null) {
                throw new AturanBisnisException("Jurnal {$locked->number} sudah dibalik dengan {$locked->reversal->number}.");
            }

            $lines = $locked->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'cost_center_id' => $line->cost_center_id,
                'description' => $line->description,
                'debit' => $line->credit,
                'credit' => $line->debit,
            ])->all();

            // Jurnal pembalik selalu di cabang jurnal yang dibalik.
            return $this->post($date ?? now(), $description, $lines, $user, $source, $event, $locked, ['branch_id' => $locked->branch_id]);
        });
    }

    /**
     * Jurnal manual dari formulir: langsung diposting, atau (bila ada alur persetujuan yang cocok)
     * disimpan sebagai draf dan langsung diajukan.
     *
     * @param  list<array{account_id: mixed, debit?: mixed, credit?: mixed, description?: ?string, cost_center_id?: mixed}>  $lines
     * @param  array<string, mixed>  $attributes  lihat docblock kelas
     */
    public function createManual(DateTimeInterface|string $date, string $description, array $lines, User $user, array $attributes = []): Journal
    {
        $rows = $this->validated($lines);
        $probe = new Journal(['total' => Uang::keDesimal(array_sum(array_column($rows, 'debit')))]);
        $probe->forceFill(['branch_id' => $attributes['branch_id'] ?? $this->branches->forNewDocument()->getKey()]);

        if (! $this->approvals->requiresApproval($probe)) {
            return $this->post($date, $description, $lines, $user, attributes: $attributes);
        }

        return DB::transaction(function () use ($date, $description, $lines, $user, $attributes) {
            $draft = $this->saveDraft($date, $description, $lines, $user, attributes: $attributes);
            $this->approvals->submit($draft, $user);

            return $draft->refresh();
        });
    }

    /**
     * Simpan (atau ganti isi) draf jurnal manual. Draf belum memengaruhi saldo akun, tetapi
     * aturan baris dan keseimbangan tetap diperiksa. Nomor dibuat saat draf pertama disimpan.
     *
     * @param  list<array{account_id: mixed, debit?: mixed, credit?: mixed, description?: ?string, cost_center_id?: mixed}>  $lines
     * @param  array<string, mixed>  $attributes  lihat docblock kelas
     */
    public function saveDraft(DateTimeInterface|string $date, string $description, array $lines, User $user, ?Journal $draft = null, array $attributes = []): Journal
    {
        $rows = $this->validated($lines);
        $date = CarbonImmutable::parse($date)->toDateString();
        $attributes = $this->manualAttributes($attributes, $date);
        app(FiscalPeriodService::class)->ensureOpen($date);

        return DB::transaction(function () use ($date, $description, $rows, $user, $draft, $attributes) {
            $journal = $draft ? $this->lock($draft) : new Journal(['user_id' => $user->getKey()]);

            if ($draft && ! $journal->isEditable()) {
                throw new AturanBisnisException("Jurnal {$journal->number} berstatus {$journal->documentStatus()->label()} dan tidak bisa diubah.");
            }

            $journal->fill([
                'journal_date' => $date,
                'description' => mb_substr(trim($description), 0, 255),
                'total' => Uang::keDesimal(array_sum(array_column($rows, 'debit'))),
            ]);

            // Cabang dan asal templat hanya diisi saat draf dibuat; tanggal balik otomatis boleh diganti.
            $journal->forceFill($journal->exists ? array_intersect_key($attributes, ['auto_reverse_on' => true]) : $attributes);

            if (! $journal->exists) {
                $journal->forceFill(['status' => DocumentStatus::Draft]);
                $journal->assignDocumentNumber();
            }

            $journal->save();
            $journal->lines()->delete();
            $this->saveLines($journal, $rows);

            activity('akuntansi')->performedOn($journal)->causedBy($user)->event($draft ? 'draft_updated' : 'draft_created')
                ->withProperties(['total' => $journal->total])
                ->log($draft ? "Draf jurnal {$journal->number} diubah" : "Draf jurnal {$journal->number} dibuat");

            return $journal->load('lines');
        });
    }

    /** Ajukan draf ke alur persetujuan yang cocok. */
    public function submit(Journal $journal, User $user, ?string $note = null): Journal
    {
        return DB::transaction(function () use ($journal, $user, $note) {
            $locked = $this->lock($journal);

            if (! $locked->isEditable()) {
                throw new AturanBisnisException("Jurnal {$locked->number} berstatus {$locked->documentStatus()->label()} dan tidak bisa diajukan.");
            }

            app(FiscalPeriodService::class)->ensureOpen($locked->journal_date);
            $this->approvals->submit($locked, $user, $note);

            return $locked->refresh();
        });
    }

    /**
     * Posting draf (tanpa alur persetujuan) atau jurnal yang sudah disetujui: masuk saldo akun.
     * Aturan baris dan kunci periode diperiksa ulang.
     */
    public function postDraft(Journal $journal, User $user): Journal
    {
        return DB::transaction(function () use ($journal, $user) {
            $locked = $this->lock($journal);
            $status = $locked->documentStatus();

            if (! in_array($status, [DocumentStatus::Draft, DocumentStatus::Approved], true)) {
                throw new AturanBisnisException("Jurnal {$locked->number} berstatus {$status->label()} dan tidak bisa diposting.");
            }

            if ($status === DocumentStatus::Draft && ($flow = $this->approvals->flowFor($locked)) !== null) {
                throw new AturanBisnisException("Jurnal {$locked->number} perlu persetujuan ({$flow->name}). Ajukan persetujuan dulu.");
            }

            app(FiscalPeriodService::class)->ensureOpen($locked->journal_date);
            $this->validated($locked->lines()->get()->map(fn ($line) => $line->only(['account_id', 'cost_center_id', 'description', 'debit', 'credit']))->all());

            $locked->moveToStatus(DocumentStatus::Posted, $user);

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('posted')
                ->withProperties(['total' => $locked->total])
                ->log("Jurnal {$locked->number} diposting");

            return $locked;
        });
    }

    /** Batalkan jurnal yang belum diposting (draf, menunggu persetujuan, atau disetujui). */
    public function cancelDraft(Journal $journal, User $user, string $reason): Journal
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($journal, $user, $reason) {
            $locked = $this->lock($journal);

            if ($locked->documentStatus()->isLocked()) {
                throw new AturanBisnisException("Jurnal {$locked->number} berstatus {$locked->documentStatus()->label()}. Jurnal yang sudah diposting dikoreksi dengan jurnal pembalik.");
            }

            if (($pending = $locked->pendingApproval()) !== null) {
                $this->approvals->cancel($pending, $user, $reason, force: true);
                $locked->refresh();
            }

            $locked->moveToStatus(DocumentStatus::Cancelled, $user, mb_substr($reason, 0, 255));

            activity('akuntansi')->performedOn($locked)->causedBy($user)->event('cancelled')
                ->withProperties(['alasan' => $reason])
                ->log("Jurnal {$locked->number} dibatalkan sebelum diposting");

            return $locked;
        });
    }

    /**
     * Balik jurnal yang dijadwalkan (auto_reverse_on), bertanggal sesuai jadwalnya. Dipanggil
     * scheduler (akuntansi:jurnal-terjadwal). Mengembalikan null bila sudah dibalik.
     */
    public function reverseScheduled(Journal $journal): ?Journal
    {
        if ($journal->reversal()->exists() || ! $journal->isPosted() || $journal->auto_reverse_on === null) {
            return null;
        }

        $user = $journal->postedBy ?? $journal->user;
        $reversal = $this->reverse($journal, "Pembalik otomatis {$journal->number}: {$journal->description}", $user, date: $journal->auto_reverse_on);

        activity('akuntansi')->performedOn($reversal)->event('auto_reversed')
            ->withProperties(['jurnal' => $journal->number, 'tanggal' => $journal->auto_reverse_on->toDateString()])
            ->log("Jurnal {$journal->number} dibalik otomatis dengan {$reversal->number}");

        return $reversal;
    }

    /**
     * Periksa baris dengan aturan jurnal tanpa menyimpan (misal templat jurnal berulang).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: int, cost_center_id: ?int, description: ?string, debit: int, credit: int}> nilai dalam sen
     */
    public function validateLines(array $lines): array
    {
        return $this->validated($lines);
    }

    public function find(Model $source, string $event): ?Journal
    {
        return Journal::query()->withoutGlobalScope(BranchScope::class)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('source_event', $event)
            ->first();
    }

    private function lock(Journal $journal): Journal
    {
        return Journal::withoutGlobalScope(BranchScope::class)->whereKey($journal->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Atribut tambahan yang sah untuk jurnal manual.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function manualAttributes(array $attributes, string $date): array
    {
        $attributes = array_intersect_key($attributes, array_flip(['auto_reverse_on', 'recurring_journal_id', 'recurring_period', 'branch_id']));

        if (array_key_exists('auto_reverse_on', $attributes)) {
            $reverseOn = blank($attributes['auto_reverse_on']) ? null : CarbonImmutable::parse($attributes['auto_reverse_on'])->toDateString();
            if ($reverseOn !== null && $reverseOn <= $date) {
                throw ValidationException::withMessages(['auto_reverse_on' => 'Tanggal balik otomatis harus setelah tanggal jurnal.']);
            }
            $attributes['auto_reverse_on'] = $reverseOn;
        }

        return $attributes;
    }

    /** @param list<array{account_id: int, cost_center_id: ?int, description: ?string, debit: int, credit: int}> $rows */
    private function saveLines(Journal $journal, array $rows): void
    {
        foreach ($rows as $row) {
            $journal->lines()->create([
                'account_id' => $row['account_id'],
                'cost_center_id' => $row['cost_center_id'],
                'description' => $row['description'],
                'debit' => Uang::keDesimal($row['debit']),
                'credit' => Uang::keDesimal($row['credit']),
            ]);
        }
    }

    /**
     * Baris yang lolos aturan (nilai dalam sen). Baris tanpa nilai (debit dan kredit 0) dilewati.
     *
     * Cost center boleh kosong; bila diisi harus ada dan aktif ($strictCostCenters = false untuk
     * jurnal pembalik, yang menyalin cost center jurnal lama walau sudah dinonaktifkan).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: int, cost_center_id: ?int, description: ?string, debit: int, credit: int}>
     */
    private function validated(array $lines, bool $strictCostCenters = true): array
    {
        $errors = [];
        $rows = [];
        $accounts = Account::withTrashed()->whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['account_id'] ?? 0), $lines)))->get()->keyBy('id');
        $costCenters = CostCenter::withTrashed()->whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['cost_center_id'] ?? 0), $lines)))->get()->keyBy('id');

        foreach (array_values($lines) as $i => $line) {
            $debit = Uang::sen($line['debit'] ?? 0);
            $credit = Uang::sen($line['credit'] ?? 0);

            if ($debit === 0 && $credit === 0) {
                continue;
            }
            if ($debit < 0 || $credit < 0) {
                $errors["lines.{$i}.debit"] = 'Nilai debit dan kredit tidak boleh negatif.';

                continue;
            }
            if ($debit > 0 && $credit > 0) {
                $errors["lines.{$i}.debit"] = 'Isi debit atau kredit saja, tidak boleh keduanya dalam satu baris.';

                continue;
            }

            $account = $accounts[(int) ($line['account_id'] ?? 0)] ?? null;
            if ($account === null) {
                $errors["lines.{$i}.account_id"] = 'Pilih akun untuk baris ini.';

                continue;
            }
            if ($account->is_header) {
                $errors["lines.{$i}.account_id"] = "Akun {$account->label()} adalah akun induk dan tidak bisa dipakai di jurnal.";

                continue;
            }
            if (! $account->is_active || $account->trashed()) {
                $errors["lines.{$i}.account_id"] = "Akun {$account->label()} tidak aktif.";

                continue;
            }

            $costCenterId = (int) ($line['cost_center_id'] ?? 0) ?: null;
            if ($costCenterId !== null) {
                $costCenter = $costCenters[$costCenterId] ?? null;
                if ($costCenter === null) {
                    $errors["lines.{$i}.cost_center_id"] = 'Cost center tidak ditemukan.';

                    continue;
                }
                if ($strictCostCenters && (! $costCenter->is_active || $costCenter->trashed())) {
                    $errors["lines.{$i}.cost_center_id"] = "Cost center {$costCenter->label()} tidak aktif.";

                    continue;
                }
            }

            $description = trim((string) ($line['description'] ?? ''));
            $rows[] = ['account_id' => $account->id, 'cost_center_id' => $costCenterId, 'description' => $description === '' ? null : mb_substr($description, 0, 255), 'debit' => $debit, 'credit' => $credit];
        }

        if ($errors === []) {
            $debit = array_sum(array_column($rows, 'debit'));
            $credit = array_sum(array_column($rows, 'credit'));

            if (count($rows) < 2) {
                $errors['lines'] = 'Jurnal minimal berisi dua baris bernilai.';
            } elseif ($debit !== $credit) {
                $errors['lines'] = sprintf(
                    'Jurnal tidak seimbang: debit %s, kredit %s (selisih %s).',
                    Format::rupiah(Uang::keDesimal($debit)), Format::rupiah(Uang::keDesimal($credit)), Format::rupiah(Uang::keDesimal(abs($debit - $credit))),
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }
}
