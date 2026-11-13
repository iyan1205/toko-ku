<?php

namespace App\Services\Sdm;

use App\Enums\DocumentStatus;
use App\Enums\StatusAbsensi;
use App\Enums\StatusCuti;
use App\Models\Absensi;
use App\Models\Cuti;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Scopes\BranchScope;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Rekap absensi per karyawan dalam satu periode (siap dipakai penggajian).
 *
 * Tiap tanggal mendapat satu kategori, berurutan: catatan absensi (hadir/terlambat/izin/sakit/
 * alpa/cuti) → cuti disetujui → hari libur/istirahat → alpha (hari kerja yang sudah lewat tanpa
 * catatan) → belum terjadi. Hari sebelum mulai kerja / sesudah berhenti tidak dihitung.
 * Periode mengikuti Pengaturan → SDM (awal periode tanggal 1, atau misal tanggal 26 bulan lalu).
 */
class AttendanceRecapService
{
    public const CATEGORIES = [
        'present' => 'Hadir', 'late' => 'Terlambat', 'permit' => 'Izin', 'sick' => 'Sakit', 'leave' => 'Cuti',
        'unpaid_leave' => 'Cuti tanpa upah', 'absent' => 'Alpha', 'off' => 'Libur', 'holiday' => 'Hari libur',
        'future' => 'Belum terjadi', 'not_employed' => 'Belum/tidak bekerja',
    ];

    public function __construct(private readonly WorkCalendar $calendar, private readonly OvertimeCalculator $overtime) {}

    /**
     * Rentang periode untuk bulan "Y-m": tanggal 1–akhir bulan, atau (awal periode bulan lalu)–(awal periode − 1).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(string $month): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $startDay = max(1, min(28, (int) setting('sdm.attendance.period_start_day', 1)));

        if ($startDay === 1) {
            return [$first, $first->endOfMonth()->startOfDay()];
        }

        return [$first->subMonthNoOverflow()->setDay($startDay), $first->setDay($startDay - 1)];
    }

    /**
     * Rincian harian satu karyawan.
     *
     * @return Collection<string, array<string, mixed>> kunci Y-m-d
     */
    public function daily(Employee $employee, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->build(collect([$employee]), $from, $to, false)[$employee->id]['days'];
    }

    /**
     * Ringkasan beberapa karyawan. $withPay = hitung upah lembur (butuh izin sdm.payroll.view).
     *
     * @param  iterable<Employee>  $employees
     * @return Collection<int, array<string, mixed>> kunci employee_id
     */
    public function summarize(iterable $employees, CarbonInterface $from, CarbonInterface $to, bool $withPay = false): Collection
    {
        return $this->build(collect($employees), $from, $to, $withPay)->map(fn (array $r) => collect($r)->except('days')->all());
    }

    /** @return Collection<int, array<string, mixed>> */
    private function build(Collection $employees, CarbonInterface $from, CarbonInterface $to, bool $withPay): Collection
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to = CarbonImmutable::parse($to)->startOfDay();
        $today = CarbonImmutable::today();
        $ids = $employees->pluck('id');
        $userToEmployee = $employees->whereNotNull('user_id')->pluck('id', 'user_id');

        $calendars = $this->calendar->daysForMany($employees, $from, $to);
        $records = Absensi::query()
            ->where(fn ($q) => $q->whereIn('employee_id', $ids)->orWhereIn('user_id', $userToEmployee->keys()))
            ->whereDate('tanggal', '>=', $from->toDateString())->whereDate('tanggal', '<=', $to->toDateString())
            ->get()->keyBy(fn (Absensi $a) => ($a->employee_id ?? $userToEmployee[$a->user_id]).'|'.$a->tanggal->toDateString());
        $leaves = $this->leaveDays($ids, $userToEmployee, $from, $to);
        $overtime = OvertimeRequest::withoutGlobalScope(BranchScope::class)->whereIn('employee_id', $ids)
            ->where('status', DocumentStatus::Approved->value)
            ->whereDate('overtime_date', '>=', $from->toDateString())->whereDate('overtime_date', '<=', $to->toDateString())
            ->get()->groupBy('employee_id');

        return $employees->mapWithKeys(function (Employee $employee) use ($calendars, $records, $leaves, $overtime, $today, $withPay) {
            $summary = array_fill_keys(array_keys(self::CATEGORIES), 0) + [
                'employee' => $employee, 'work_days' => 0, 'late_minutes' => 0, 'early_count' => 0, 'early_minutes' => 0,
                'missing_out' => 0, 'worked_rest_days' => 0, 'work_minutes' => 0, 'overtime_minutes' => 0, 'overtime_pay' => null,
                'overtime_pay_missing' => null, 'days' => collect(),
            ];
            $join = $employee->join_date ? CarbonImmutable::parse($employee->join_date)->startOfDay() : null;
            $end = $employee->end_date ? CarbonImmutable::parse($employee->end_date)->startOfDay() : null;

            foreach ($calendars[$employee->id] as $key => $day) {
                $date = $day['date'];
                $record = $records->get($employee->id.'|'.$key);
                $leave = $leaves[$employee->id.'|'.$key] ?? null;
                $employed = ($join === null || $date->gte($join)) && ($end === null || $date->lte($end));

                $category = match (true) {
                    ! $employed => 'not_employed',
                    $record !== null => $this->recordCategory($record, $leave),
                    $leave !== null && $day['type'] === 'work' => $leave['paid'] ? $this->leaveCategory($leave['status']) : 'unpaid_leave',
                    $day['type'] === 'holiday' => 'holiday',
                    $day['type'] === 'off' => 'off',
                    $date->gt($today) => 'future',
                    default => 'absent',
                };

                if ($employed && $day['type'] === 'work') {
                    $summary['work_days']++;
                }
                $summary[$category]++;
                if ($record?->clock_in !== null) {
                    $summary['late_minutes'] += $record->late_minutes;
                    $summary['early_count'] += $record->early_leave_minutes > 0 ? 1 : 0;
                    $summary['early_minutes'] += $record->early_leave_minutes;
                    $summary['work_minutes'] += (int) ($record->durasiMenit() ?? 0);
                    $summary['missing_out'] += $record->clock_out === null && $date->lt($today) ? 1 : 0;
                    $summary['worked_rest_days'] += $day['type'] !== 'work' ? 1 : 0;
                }

                $summary['days']->put($key, $day + ['record' => $record, 'leave' => $leave['name'] ?? null, 'category' => $category, 'overtime_minutes' => 0]);
            }

            foreach ($overtime->get($employee->id, collect()) as $request) {
                $key = $request->overtime_date->toDateString();
                $summary['overtime_minutes'] += $request->duration_minutes;
                if ($summary['days']->has($key)) {
                    $summary['days']->put($key, ['overtime_minutes' => $summary['days'][$key]['overtime_minutes'] + $request->duration_minutes] + $summary['days'][$key]);
                }
                if ($withPay) {
                    $day = $summary['days'][$key] ?? null;
                    $dayType = $day ? ($day['holiday'] !== null ? 'holiday' : ($day['type'] === 'work' ? 'work' : 'rest')) : null;
                    $pay = $this->overtime->calculate($employee, $request->overtime_date, $request->duration_minutes, $dayType);
                    if ($pay['amount'] === null) {
                        $summary['overtime_pay_missing'] = $pay['reason'];
                    } else {
                        $summary['overtime_pay'] = Uang::keDesimal(Uang::sen($summary['overtime_pay'] ?? 0) + Uang::sen($pay['amount']));
                    }
                }
            }

            return [$employee->id => $summary];
        });
    }

    private function recordCategory(Absensi $record, ?array $leave): string
    {
        return match ($record->status) {
            StatusAbsensi::Hadir => 'present',
            StatusAbsensi::Terlambat => 'late',
            StatusAbsensi::Izin => 'permit',
            StatusAbsensi::Sakit => 'sick',
            StatusAbsensi::Alpa => 'absent',
            StatusAbsensi::Cuti => ($leave['paid'] ?? true) ? 'leave' : 'unpaid_leave',
        };
    }

    private function leaveCategory(?StatusAbsensi $status): string
    {
        return match ($status) {
            StatusAbsensi::Izin => 'permit',
            StatusAbsensi::Sakit => 'sick',
            default => 'leave',
        };
    }

    /**
     * Cuti disetujui per hari: "employee_id|Y-m-d" => [name, status, paid].
     *
     * @param  Collection<int, int>  $ids
     * @param  Collection<int, int>  $userToEmployee
     * @return array<string, array{name: string, status: ?StatusAbsensi, paid: bool}>
     */
    private function leaveDays(Collection $ids, Collection $userToEmployee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = [];
        $leaves = Cuti::query()->with('jenisCuti')->where('status', StatusCuti::Disetujui)
            ->where(fn ($q) => $q->whereIn('employee_id', $ids)->orWhereIn('user_id', $userToEmployee->keys()))
            ->whereDate('tanggal_mulai', '<=', $to->toDateString())->whereDate('tanggal_selesai', '>=', $from->toDateString())
            ->get();

        foreach ($leaves as $leave) {
            $start = CarbonImmutable::parse($leave->tanggal_mulai)->max($from);
            $finish = CarbonImmutable::parse($leave->tanggal_selesai)->min($to);
            for ($d = $start; $d->lte($finish); $d = $d->addDay()) {
                $days[($leave->employee_id ?? $userToEmployee[$leave->user_id]).'|'.$d->toDateString()] = [
                    'name' => $leave->jenisCuti?->nama ?? 'Cuti',
                    'status' => $leave->jenisCuti?->status_absensi,
                    'paid' => (bool) ($leave->jenisCuti?->is_paid ?? true),
                ];
            }
        }

        return $days;
    }
}
