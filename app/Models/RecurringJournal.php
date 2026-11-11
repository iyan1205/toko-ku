<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use App\Support\Format;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Templat jurnal berulang, misal sewa atau penyusutan bulanan. Scheduler
 * (akuntansi:jurnal-terjadwal) membuat jurnal setiap next_run_on tiba lewat
 * App\Services\Accounting\RecurringJournalService. Jurnal hasil templat berstatus posted
 * (auto_post) atau draf, dan tetap melewati alur persetujuan jurnal bila ada yang cocok.
 *
 * day_of_month 1–31; bulan yang lebih pendek memakai tanggal terakhirnya.
 */
class RecurringJournal extends Model
{
    use BelongsToBranch, RecordsActivity, SoftDeletes, TracksAuthors;

    public const INTERVALS = [1 => 'Setiap bulan', 2 => 'Setiap 2 bulan', 3 => 'Setiap 3 bulan', 6 => 'Setiap 6 bulan', 12 => 'Setiap tahun'];

    protected $fillable = ['name', 'description', 'interval_months', 'day_of_month', 'start_on', 'end_on', 'auto_post', 'is_active'];

    protected function casts(): array
    {
        return [
            'interval_months' => 'integer',
            'day_of_month' => 'integer',
            'start_on' => 'date',
            'end_on' => 'date',
            'next_run_on' => 'date',
            'last_run_on' => 'date',
            'auto_post' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringJournalLine::class)->orderBy('id');
    }

    public function journals(): HasMany
    {
        return $this->hasMany(Journal::class);
    }

    /** Templat aktif yang jadwalnya sudah tiba. */
    public function scopeDue(Builder $query, string $today): Builder
    {
        return $query->where('is_active', true)->whereNotNull('next_run_on')->whereDate('next_run_on', '<=', $today);
    }

    public function total(): string
    {
        return (string) $this->lines->sum(fn (RecurringJournalLine $line) => (float) $line->debit);
    }

    /** "Setiap bulan, tanggal 25" */
    public function scheduleLabel(): string
    {
        $tanggal = $this->day_of_month >= 31 ? 'tanggal terakhir' : "tanggal {$this->day_of_month}";

        return (self::INTERVALS[$this->interval_months] ?? "Setiap {$this->interval_months} bulan").", {$tanggal}";
    }

    public function statusLabel(): string
    {
        return match (true) {
            ! $this->is_active => 'Nonaktif',
            $this->next_run_on === null => 'Selesai',
            default => 'Berikutnya '.Format::tanggal($this->next_run_on),
        };
    }

    protected function activityLogName(): string
    {
        return 'akuntansi';
    }

    protected function activityLogLabel(): string
    {
        return 'Jurnal berulang';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['name', 'description', 'interval_months', 'day_of_month', 'start_on', 'end_on', 'auto_post', 'is_active'];
    }
}
