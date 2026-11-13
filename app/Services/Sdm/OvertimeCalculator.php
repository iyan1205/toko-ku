<?php

namespace App\Services\Sdm;

use App\Models\Employee;
use App\Models\OvertimeRule;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Upah lembur dari aturan yang berlaku pada tanggal lembur (OvertimeRule, Pengaturan → SDM).
 *
 *   multiplier : upah sejam = gaji pokok / pembagi (173); tiap jam dikali pengali tingkatnya.
 *                Hari kerja dan hari istirahat/libur (5 atau 6 hari kerja seminggu) punya tingkat sendiri.
 *   flat       : tarif tetap per jam.
 *
 * Hitungan memakai bilangan bulat: pengali dalam milli (1,5 = 1500), uang dalam sen.
 */
class OvertimeCalculator
{
    /** @var array<string, ?OvertimeRule> */
    private array $rules = [];

    public function __construct(private readonly WorkCalendar $calendar) {}

    /**
     * Upah lembur satu pengajuan. amount/hourly berupa desimal Rupiah ("125000.00"), atau null bila
     * tidak bisa dihitung (alasannya di reason).
     *
     * @return array{day_type: string, minutes: int, paid_minutes: int, weighted_hours: string, hourly: ?string, amount: ?string, rule: ?OvertimeRule, reason: ?string}
     */
    public function calculate(Employee $employee, CarbonInterface $date, int $minutes, ?string $dayType = null): array
    {
        $date = CarbonImmutable::parse($date)->startOfDay();
        $dayType ??= $this->dayType($employee, $date);
        $rule = $this->rules[$date->toDateString()] ??= OvertimeRule::for($date);
        $result = ['day_type' => $dayType, 'minutes' => $minutes, 'paid_minutes' => 0, 'weighted_hours' => '0', 'hourly' => null, 'amount' => null, 'rule' => $rule, 'reason' => null];

        if ($rule === null) {
            return ['reason' => 'Belum ada aturan upah lembur yang berlaku.'] + $result;
        }

        $paid = $this->paidMinutes($rule, $minutes);
        $weightedMilli = $rule->mode === 'flat' ? $paid * 1000 : $this->weightedMilliMinutes($rule->tiers($dayType, $this->workweek()), $paid);
        $result = ['paid_minutes' => $paid, 'weighted_hours' => rtrim(rtrim(number_format($weightedMilli / 60000, 3, '.', ''), '0'), '.')] + $result;

        if ($employee->overtime_eligible === false) {
            return ['amount' => '0.00', 'reason' => 'Karyawan tidak mendapat upah lembur.'] + $result;
        }

        if ($rule->mode === 'flat') {
            $rate = Uang::sen($rule->flat_rate ?? 0);

            return ['hourly' => Uang::keDesimal($rate), 'amount' => Uang::keDesimal(Uang::kaliBagi($rate, $paid, 60))] + $result;
        }

        if ($employee->base_salary === null || Uang::sen($employee->base_salary) <= 0) {
            return ['reason' => 'Gaji pokok karyawan belum diisi.'] + $result;
        }

        $salary = Uang::sen($employee->base_salary);
        $divisor = max(1, $rule->divisor);

        return [
            'hourly' => Uang::keDesimal(intdiv($salary * 2 + $divisor, $divisor * 2)),
            // gaji × (menit tertimbang / 60) / pembagi, sekali bulat di akhir.
            'amount' => Uang::keDesimal(Uang::kaliBagi($salary, $weightedMilli, $divisor * 60000)),
        ] + $result;
    }

    /** work | rest (libur mingguan/jadwal libur) | holiday (hari libur nasional/cabang, walau berjadwal). */
    public function dayType(Employee $employee, CarbonInterface $date): string
    {
        $day = $this->calendar->day($employee, $date);

        return match (true) {
            $day['holiday'] !== null => 'holiday',
            $day['type'] === 'work' => 'work',
            default => 'rest',
        };
    }

    public function workweek(): int
    {
        return (int) setting('sdm.workweek', 6) === 5 ? 5 : 6;
    }

    private function paidMinutes(OvertimeRule $rule, int $minutes): int
    {
        if ($minutes < $rule->min_minutes) {
            return 0;
        }

        return $rule->rounding_minutes > 0 ? intdiv($minutes, $rule->rounding_minutes) * $rule->rounding_minutes : $minutes;
    }

    /**
     * Menit × pengali (milli) per tingkat: tingkat {hours: 1, multiplier: 1.5} memakan 60 menit pertama, dst.
     *
     * @param  list<array{hours: ?int, multiplier: string}>  $tiers
     */
    private function weightedMilliMinutes(array $tiers, int $minutes): int
    {
        $total = 0;
        foreach ($tiers as $tier) {
            if ($minutes <= 0) {
                break;
            }
            $take = $tier['hours'] === null ? $minutes : min($minutes, (int) $tier['hours'] * 60);
            $total += $take * Uang::milli($tier['multiplier']);
            $minutes -= $take;
        }

        // Menit di luar tingkat terakhir (tanpa "sisa") memakai pengali tingkat terakhir.
        if ($minutes > 0 && $tiers !== []) {
            $total += $minutes * Uang::milli(end($tiers)['multiplier']);
        }

        return $total;
    }
}
