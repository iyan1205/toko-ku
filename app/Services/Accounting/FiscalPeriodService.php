<?php

namespace App\Services\Accounting;

use App\Enums\FiscalPeriodStatus;
use App\Exceptions\AturanBisnisException;
use App\Models\Company;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kunci periode bulanan (Akuntansi → Kunci Periode).
 *
 * Transaksi yang membukukan stok, kas/bank, hutang-piutang, atau jurnal dengan tanggal di bulan
 * yang ditutup ditolak: lewat aturan validasi App\Rules\OpenPeriod di formulir, dan ensureOpen()
 * di service (JournalService, SaleService, PurchaseService, dan lain-lain) sebagai pengaman akhir.
 * Pembatalan dokumen lama tetap boleh: pembaliknya dibukukan di tanggal pembatalan.
 *
 * Didaftarkan scoped di AppServiceProvider: daftar bulan tertutup dibaca sekali per request.
 */
class FiscalPeriodService
{
    /** @var array<int, array<string, true>> company_id => ['2026-10' => true] */
    private array $closed = [];

    private ?int $defaultCompany = null;

    public function isClosed(DateTimeInterface|string $date, ?int $companyId = null): bool
    {
        $companyId ??= $this->defaultCompanyId();

        return isset($this->closedMonths($companyId)[CarbonImmutable::parse($date)->format('Y-m')]);
    }

    /** @throws AturanBisnisException bila tanggal ada di periode yang ditutup */
    public function ensureOpen(DateTimeInterface|string $date, ?int $companyId = null): void
    {
        if ($this->isClosed($date, $companyId)) {
            throw new AturanBisnisException($this->message($date));
        }
    }

    public function message(DateTimeInterface|string $date): string
    {
        return 'Periode '.Format::bulan($date).' sudah ditutup. Transaksi bertanggal di bulan itu tidak bisa disimpan atau diubah.';
    }

    /** Tutup bulan. Bulan yang belum berjalan tidak bisa ditutup. */
    public function close(int $year, int $month, User $user, ?string $note = null): FiscalPeriod
    {
        $first = $this->firstDay($year, $month);

        if ($first->greaterThan(CarbonImmutable::now()->startOfMonth())) {
            throw new AturanBisnisException('Periode '.Format::bulan($first).' belum berjalan dan tidak bisa ditutup.');
        }

        return DB::transaction(function () use ($first, $user, $note) {
            $period = $this->lockedPeriod($first);

            if ($period->isClosed()) {
                throw new AturanBisnisException('Periode '.Format::bulan($first).' sudah ditutup.');
            }

            $period->forceFill([
                'status' => FiscalPeriodStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $user->getKey(),
                'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            ])->save();

            activity('akuntansi')->performedOn($period)->causedBy($user)->event('period_closed')
                ->withProperties(['periode' => Format::bulan($first), 'catatan' => $period->note])
                ->log('Periode '.Format::bulan($first).' ditutup');

            $this->closed = [];

            return $period;
        });
    }

    /** Buka kembali bulan yang ditutup. Alasan wajib dan tercatat di log aktivitas. */
    public function reopen(int $year, int $month, User $user, string $reason): FiscalPeriod
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan minimal 5 karakter.']);
        }

        $first = $this->firstDay($year, $month);

        return DB::transaction(function () use ($first, $user, $reason) {
            $period = $this->lockedPeriod($first);

            if (! $period->isClosed()) {
                throw new AturanBisnisException('Periode '.Format::bulan($first).' masih terbuka.');
            }

            $period->forceFill([
                'status' => FiscalPeriodStatus::Open,
                'reopened_at' => now(),
                'reopened_by' => $user->getKey(),
                'note' => mb_substr($reason, 0, 255),
            ])->save();

            activity('akuntansi')->performedOn($period)->causedBy($user)->event('period_reopened')
                ->withProperties(['periode' => Format::bulan($first), 'alasan' => $reason])
                ->log('Periode '.Format::bulan($first).' dibuka kembali');

            $this->closed = [];

            return $period;
        });
    }

    /** @return array<string, true> */
    private function closedMonths(int $companyId): array
    {
        return $this->closed[$companyId] ??= FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('status', FiscalPeriodStatus::Closed->value)
            ->get(['year', 'month'])
            ->mapWithKeys(fn (FiscalPeriod $p) => [sprintf('%04d-%02d', $p->year, $p->month) => true])
            ->all();
    }

    private function lockedPeriod(CarbonImmutable $first): FiscalPeriod
    {
        $companyId = $this->defaultCompanyId();

        DB::table('fiscal_periods')->insertOrIgnore([
            'company_id' => $companyId,
            'year' => $first->year,
            'month' => $first->month,
            'status' => FiscalPeriodStatus::Open->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return FiscalPeriod::query()
            ->where('company_id', $companyId)
            ->where('year', $first->year)
            ->where('month', $first->month)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function firstDay(int $year, int $month): CarbonImmutable
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw new AturanBisnisException('Periode tidak valid.');
        }

        return CarbonImmutable::create($year, $month, 1);
    }

    private function defaultCompanyId(): int
    {
        return $this->defaultCompany ??= Company::default()->getKey();
    }
}
