<?php

namespace App\Services\Sdm;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Jadwal;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Hari kerja seorang karyawan: jadwal (Jadwal Kerja) lebih dulu; tanpa jadwal, hari libur
 * nasional/cabang dan hari libur mingguan (Pengaturan → SDM: 5 atau 6 hari kerja) menentukan.
 *
 * Hasil per tanggal: type work|off|holiday, shift (bila ada), dan nama hari libur (bila ada).
 */
class WorkCalendar
{
    /** @var array<string, array<string, string>> memo libur per cabang+rentang */
    private array $holidayMemo = [];

    /**
     * @return Collection<string, array{date: CarbonImmutable, type: string, shift: ?Shift, holiday: ?string, scheduled: bool}> kunci Y-m-d
     */
    public function days(Employee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->daysForMany(collect([$employee]), $from, $to)->get($employee->id);
    }

    /**
     * Kalender kerja beberapa karyawan sekaligus (satu query jadwal untuk semuanya).
     *
     * @param  iterable<Employee>  $employees
     * @return Collection<int, Collection<string, array{date: CarbonImmutable, type: string, shift: ?Shift, holiday: ?string, scheduled: bool}>> kunci employee_id
     */
    public function daysForMany(iterable $employees, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $employees = collect($employees);
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        $weeklyOff = $this->weeklyOffDays();
        $dates = collect(CarbonPeriod::create($from, $to))->map(fn ($d) => CarbonImmutable::parse($d));
        $schedules = Jadwal::query()->with('shift')->whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('tanggal', '>=', $from->toDateString())->whereDate('tanggal', '<=', $to->toDateString())
            ->get()->keyBy(fn (Jadwal $j) => $j->employee_id.'|'.$j->tanggal->toDateString());

        return $employees->mapWithKeys(function (Employee $employee) use ($from, $to, $weeklyOff, $dates, $schedules) {
            $holidays = $this->holidays($from, $to, $employee->branch_id);

            return [$employee->id => $dates->mapWithKeys(function (CarbonImmutable $date) use ($employee, $holidays, $weeklyOff, $schedules) {
                $key = $date->toDateString();
                $holiday = $holidays[$key] ?? null;
                $schedule = $schedules->get($employee->id.'|'.$key);

                $type = match (true) {
                    $schedule !== null => $schedule->libur() ? 'off' : 'work',
                    $holiday !== null => 'holiday',
                    in_array($date->dayOfWeekIso, $weeklyOff, true) => 'off',
                    default => 'work',
                };

                return [$key => ['date' => $date, 'type' => $type, 'shift' => $schedule?->shift, 'holiday' => $holiday, 'scheduled' => $schedule !== null]];
            })];
        });
    }

    /** @return array{date: CarbonImmutable, type: string, shift: ?Shift, holiday: ?string, scheduled: bool} */
    public function day(Employee $employee, CarbonInterface $date): array
    {
        return $this->days($employee, $date, $date)->first();
    }

    /**
     * Hari libur (Y-m-d => nama) untuk cabang ini dalam rentang.
     *
     * @return array<string, string>
     */
    public function holidays(CarbonInterface $from, CarbonInterface $to, ?int $branchId): array
    {
        $key = ($branchId ?? 0).'|'.$from->toDateString().'|'.$to->toDateString();

        return $this->holidayMemo[$key] ??= Holiday::query()->forBranch($branchId)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get()->mapWithKeys(fn (Holiday $h) => [$h->date->toDateString() => $h->name])->all();
    }

    /** Hari libur mingguan (1 = Senin … 7 = Minggu). Bawaan 6 hari kerja: Minggu libur. @return list<int> */
    public function weeklyOffDays(): array
    {
        return (int) setting('sdm.workweek', 6) === 5 ? [6, 7] : [7];
    }

    /** Jam masuk/pulang bawaan bila hari kerja tanpa shift (Pengaturan → SDM). @return array{0: ?string, 1: ?string} */
    public function defaultHours(): array
    {
        $in = setting('sdm.attendance.default_in', config('pos.absensi.jam_masuk'));
        $out = setting('sdm.attendance.default_out');

        return [is_string($in) && preg_match('/^\d{2}:\d{2}$/', $in) ? $in : null, is_string($out) && preg_match('/^\d{2}:\d{2}$/', $out) ? $out : null];
    }
}
