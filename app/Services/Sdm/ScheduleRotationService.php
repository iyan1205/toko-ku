<?php

namespace App\Services\Sdm;

use App\Exceptions\AturanBisnisException;
use App\Models\Employee;
use App\Models\Jadwal;
use App\Models\ShiftRotation;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Terapkan pola rotasi shift ke jadwal karyawan dalam satu rentang tanggal.
 * Hari ke-n pola = (selisih hari dari tanggal mulai + offset) mod panjang pola, jadi rotasi bisa
 * dilanjutkan pada rentang berikutnya dengan tanggal mulai yang sama-sama dihitung dari awal pola.
 */
class ScheduleRotationService
{
    public const MAX_DAYS = 92;

    public function __construct(private readonly WorkCalendar $calendar) {}

    /**
     * @param  list<int>  $employeeIds
     * @return array{created: int, updated: int, skipped: int}
     */
    public function apply(ShiftRotation $rotation, array $employeeIds, CarbonImmutable $start, CarbonImmutable $end,
        int $offset = 0, bool $overwrite = false, bool $skipHolidays = false): array
    {
        $pattern = array_values($rotation->pattern ?? []);
        if ($pattern === []) {
            throw new AturanBisnisException('Pola rotasi masih kosong.');
        }
        if ($end->lt($start) || $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw new AturanBisnisException('Rentang tanggal paling panjang '.self::MAX_DAYS.' hari.');
        }

        $employees = Employee::query()->whereIn('id', $employeeIds)->get(['id', 'user_id', 'branch_id', 'name']);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        DB::transaction(function () use ($pattern, $employees, $start, $end, $offset, $overwrite, $skipHolidays, &$counts) {
            // Jadwal lama dari grid mingguan bisa hanya ber-user_id (akun ditautkan ke karyawan belakangan).
            $byUser = $employees->whereNotNull('user_id')->pluck('id', 'user_id');
            $existing = Jadwal::query()
                ->where(fn ($q) => $q->whereIn('employee_id', $employees->pluck('id'))->orWhereIn('user_id', $byUser->keys()))
                ->whereDate('tanggal', '>=', $start->toDateString())->whereDate('tanggal', '<=', $end->toDateString())
                ->get()->keyBy(fn (Jadwal $j) => ($j->employee_id ?? $byUser[$j->user_id]).'|'.$j->tanggal->toDateString());

            foreach ($employees as $employee) {
                $holidays = $skipHolidays ? $this->calendar->holidays($start, $end, $employee->branch_id) : [];

                foreach (CarbonPeriod::create($start, $end) as $i => $day) {
                    $date = $day->toDateString();
                    $shiftId = $pattern[($i + $offset) % count($pattern)];
                    $schedule = $existing->get($employee->id.'|'.$date);

                    if (isset($holidays[$date]) || ($schedule !== null && ! $overwrite)) {
                        $counts['skipped']++;

                        continue;
                    }

                    $schedule ??= new Jadwal(['tanggal' => $date]);
                    $isNew = ! $schedule->exists;
                    $schedule->employee_id = $employee->id;
                    $schedule->user_id = $employee->user_id;
                    $schedule->shift_id = $shiftId === null ? null : (int) $shiftId;

                    if ($isNew || $schedule->isDirty()) {
                        $schedule->save();
                        $counts[$isNew ? 'created' : 'updated']++;
                    } else {
                        $counts['skipped']++;
                    }
                }
            }
        });

        activity('sdm')->performedOn($rotation)->causedBy(auth()->user())->event('applied')
            ->withProperties(['karyawan' => $employees->count(), 'dari' => $start->toDateString(), 'sampai' => $end->toDateString()] + $counts)
            ->log("Rotasi {$rotation->name} diterapkan ke {$employees->count()} karyawan");

        return $counts;
    }
}
