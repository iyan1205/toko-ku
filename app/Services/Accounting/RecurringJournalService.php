<?php

namespace App\Services\Accounting;

use App\Exceptions\AturanBisnisException;
use App\Models\Journal;
use App\Models\RecurringJournal;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\Format;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jurnal berulang. Jadwal: setiap interval_months bulan pada day_of_month (bulan pendek memakai
 * tanggal terakhirnya), mulai start_on sampai end_on (opsional).
 *
 * Scheduler (akuntansi:jurnal-terjadwal, harian) memanggil runDue(). Jadwal yang terlewat
 * (misal scheduler mati beberapa hari) dibuat semuanya, maksimal MAX_CATCH_UP per templat per
 * jalan. Satu periode (YYYY-MM) per templat hanya dibuat sekali (unik di tabel journals).
 *
 * Jurnal hasil templat dibuat atas nama pembuat templat: auto_post → createManual() (posted,
 * atau diajukan bila ada alur persetujuan jurnal yang cocok); selain itu disimpan sebagai draf.
 * Kegagalan (misal periode dikunci) dicatat di last_error dan Log Aktivitas; templat lain tetap jalan.
 */
class RecurringJournalService
{
    public const MAX_CATCH_UP = 12;

    public function __construct(
        private readonly JournalService $journals,
        private readonly CurrentBranch $branches,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, description, interval_months, day_of_month, start_on, end_on, auto_post, is_active, lines[]
     */
    public function save(array $data, User $user, ?RecurringJournal $template = null): RecurringJournal
    {
        $rows = $this->journals->validateLines($data['lines'] ?? []);

        return DB::transaction(function () use ($data, $rows, $template) {
            $template ??= new RecurringJournal;
            $template->fill([
                'name' => trim($data['name']),
                'description' => trim($data['description']),
                'interval_months' => (int) $data['interval_months'],
                'day_of_month' => (int) $data['day_of_month'],
                'start_on' => $data['start_on'],
                'end_on' => $data['end_on'] ?? null,
                'auto_post' => (bool) ($data['auto_post'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);

            if (! $template->exists) {
                $template->forceFill(['branch_id' => $this->branches->forNewDocument()->getKey()]);
            }

            // Jadwal dihitung ulang dari tanggal terakhir yang sudah dibuat (bila ada).
            $template->forceFill([
                'next_run_on' => $this->nextAfter($template, $template->last_run_on ? CarbonImmutable::parse($template->last_run_on) : null)?->toDateString(),
                'last_error' => null,
            ]);
            $template->save();

            $template->lines()->delete();
            foreach ($rows as $row) {
                $template->lines()->create([
                    'account_id' => $row['account_id'],
                    'cost_center_id' => $row['cost_center_id'],
                    'description' => $row['description'],
                    'debit' => Uang::keDesimal($row['debit']),
                    'credit' => Uang::keDesimal($row['credit']),
                ]);
            }

            return $template->load('lines');
        });
    }

    public function delete(RecurringJournal $template): void
    {
        $template->delete();
    }

    /**
     * Jalankan semua templat yang jadwalnya sudah tiba.
     *
     * @return array{created: int, failed: int}
     */
    public function runDue(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $result = ['created' => 0, 'failed' => 0];

        RecurringJournal::query()->withoutGlobalScope(BranchScope::class)->due($today->toDateString())->orderBy('id')
            ->each(function (RecurringJournal $template) use ($today, &$result) {
                $created = $this->run($template, $today);
                $result['created'] += count($created);
                $result['failed'] += $template->refresh()->last_error ? 1 : 0;
            });

        return $result;
    }

    /**
     * Buat jurnal untuk semua jadwal templat ini sampai $today.
     *
     * @return list<Journal>
     */
    public function run(RecurringJournal $template, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $created = [];

        while (count($created) < self::MAX_CATCH_UP && $template->is_active && $template->next_run_on !== null && $template->next_run_on->lte($today)) {
            $date = CarbonImmutable::parse($template->next_run_on);

            try {
                $created[] = $this->generate($template, $date);
            } catch (Throwable $e) {
                $message = mb_substr($e->getMessage(), 0, 250);
                $template->forceFill(['last_error' => "{$date->format('Y-m')}: {$message}"])->save();
                Log::warning("Jurnal berulang {$template->name} gagal untuk {$date->toDateString()}", ['error' => $e->getMessage()]);
                activity('akuntansi')->performedOn($template)->event('recurring_failed')
                    ->withProperties(['templat' => $template->name, 'tanggal' => $date->toDateString(), 'pesan' => $e->getMessage()])
                    ->log("Jurnal berulang {$template->name} gagal dibuat untuk ".Format::tanggal($date));

                break;
            }

            $template->forceFill([
                'last_run_on' => $date->toDateString(),
                'next_run_on' => $this->nextAfter($template, $date)?->toDateString(),
                'last_error' => null,
            ])->save();
        }

        return $created;
    }

    /** Jurnal untuk satu tanggal jadwal. Periode yang sudah dibuat mengembalikan jurnal yang ada. */
    public function generate(RecurringJournal $template, CarbonImmutable $date): Journal
    {
        $period = $date->format('Y-m');
        $existing = Journal::query()->withoutGlobalScope(BranchScope::class)
            ->where('recurring_journal_id', $template->getKey())->where('recurring_period', $period)->first();
        if ($existing !== null) {
            return $existing;
        }

        $user = $template->creator ?? $template->updater;
        if ($user === null || ! $user->is_active) {
            throw new AturanBisnisException('Pembuat templat sudah tidak aktif. Ubah templat ini dengan pengguna yang aktif.');
        }

        $template->loadMissing('lines');
        $lines = $template->lines->map(fn ($line) => $line->only(['account_id', 'cost_center_id', 'description', 'debit', 'credit']))->all();
        $description = "{$template->description} (".Format::bulan($date).')';
        $attributes = ['recurring_journal_id' => $template->getKey(), 'recurring_period' => $period, 'branch_id' => $template->branch_id];

        $journal = $template->auto_post
            ? $this->journals->createManual($date, $description, $lines, $user, $attributes)
            : $this->journals->saveDraft($date, $description, $lines, $user, attributes: $attributes);

        activity('akuntansi')->performedOn($journal)->causedBy($user)->event('recurring_created')
            ->withProperties(['templat' => $template->name, 'periode' => $period])
            ->log("Jurnal {$journal->number} dibuat dari jurnal berulang {$template->name}");

        return $journal;
    }

    /** Tanggal jadwal pertama setelah $after (null = jadwal pertama sejak start_on), atau null bila selesai. */
    public function nextAfter(RecurringJournal $template, ?CarbonImmutable $after): ?CarbonImmutable
    {
        $start = CarbonImmutable::parse($template->start_on)->startOfDay();
        $end = $template->end_on ? CarbonImmutable::parse($template->end_on)->startOfDay() : null;
        $interval = max(1, (int) $template->interval_months);
        $month = $start->startOfMonth();

        for ($i = 0; $i < 1200; $i++, $month = $month->addMonthsNoOverflow($interval)) {
            $date = $month->setDay(min((int) $template->day_of_month, $month->daysInMonth));

            if ($date->lt($start) || ($after !== null && $date->lte($after))) {
                continue;
            }

            return $end !== null && $date->gt($end) ? null : $date;
        }

        return null;
    }
}
