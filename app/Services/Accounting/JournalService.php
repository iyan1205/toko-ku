<?php

namespace App\Services\Accounting;

use App\Enums\DocumentType;
use App\Exceptions\AturanBisnisException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\User;
use App\Services\Settings\DocumentNumberService;
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
 */
class JournalService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /**
     * @param  list<array{account_id: mixed, debit?: mixed, credit?: mixed, description?: ?string}>  $lines  nilai dalam Rupiah
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
    ): Journal {
        if ($source !== null && ($existing = $this->find($source, (string) $event))) {
            return $existing;
        }

        $rows = $this->validated($lines);
        $date = CarbonImmutable::parse($date)->toDateString();

        return DB::transaction(function () use ($date, $description, $rows, $user, $source, $event, $reversalOf) {
            $journal = Journal::create([
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

            foreach ($rows as $row) {
                $journal->lines()->create([
                    'account_id' => $row['account_id'],
                    'description' => $row['description'],
                    'debit' => Uang::keDesimal($row['debit']),
                    'credit' => Uang::keDesimal($row['credit']),
                ]);
            }

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
            $locked = Journal::whereKey($journal->getKey())->lockForUpdate()->with(['lines', 'reversal'])->firstOrFail();

            if ($locked->reversal_of_id !== null) {
                throw new AturanBisnisException("Jurnal {$locked->number} adalah jurnal pembalik dan tidak bisa dibalik lagi.");
            }
            if ($locked->reversal !== null) {
                throw new AturanBisnisException("Jurnal {$locked->number} sudah dibalik dengan {$locked->reversal->number}.");
            }

            $lines = $locked->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'description' => $line->description,
                'debit' => $line->credit,
                'credit' => $line->debit,
            ])->all();

            return $this->post($date ?? now(), $description, $lines, $user, $source, $event, $locked);
        });
    }

    public function find(Model $source, string $event): ?Journal
    {
        return Journal::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('source_event', $event)
            ->first();
    }

    /**
     * Baris yang lolos aturan (nilai dalam sen). Baris tanpa nilai (debit dan kredit 0) dilewati.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: int, description: ?string, debit: int, credit: int}>
     */
    private function validated(array $lines): array
    {
        $errors = [];
        $rows = [];
        $accounts = Account::withTrashed()->whereIn('id', array_filter(array_map(fn ($l) => (int) ($l['account_id'] ?? 0), $lines)))->get()->keyBy('id');

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

            $description = trim((string) ($line['description'] ?? ''));
            $rows[] = ['account_id' => $account->id, 'description' => $description === '' ? null : mb_substr($description, 0, 255), 'debit' => $debit, 'credit' => $credit];
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
