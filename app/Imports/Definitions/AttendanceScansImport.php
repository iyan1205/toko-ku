<?php

namespace App\Imports\Definitions;

use App\Imports\ImportDefinition;
use App\Models\Employee;
use App\Models\User;
use App\Services\Sdm\AbsensiService;
use Carbon\CarbonImmutable;

/**
 * Impor data scan mesin fingerprint (ekspor Excel/CSV dari mesin). Satu baris = satu scan.
 * Karyawan dicocokkan lewat PIN absen (Karyawan → PIN mesin absen) atau kode karyawan.
 * Scan pertama hari itu = jam masuk, scan terakhir = jam pulang (AbsensiService::catatScan).
 */
class AttendanceScansImport extends ImportDefinition
{
    /** @var array<string, Employee> */
    private array $byPin = [];

    /** @var array<string, int> */
    private array $seen = [];

    public function __construct(private readonly AbsensiService $attendance) {}

    public function key(): string
    {
        return 'attendance_scans';
    }

    public function title(): string
    {
        return 'Data mesin fingerprint';
    }

    public function indexRoute(): string
    {
        return 'attendances.index';
    }

    public function matchingRule(): string
    {
        return 'Setiap baris adalah satu scan. Scan pertama dalam sehari menjadi jam masuk dan scan terakhir menjadi jam pulang; '
            .'scan dini hari setelah shift malam dihitung ke hari sebelumnya. Absensi izin, sakit, atau cuti yang sudah dicatat tidak ditimpa. '
            .'Mengimpor berkas yang sama dua kali tidak menggandakan data.';
    }

    public function maxRows(): int
    {
        return 10000;
    }

    public function columns(): array
    {
        return [
            'pin' => ['label' => 'PIN', 'required' => true, 'hint' => 'PIN/ID karyawan di mesin, atau kode karyawan.', 'example' => '1001', 'text' => true],
            'nama' => ['label' => 'Nama', 'hint' => 'Tidak dipakai untuk mencocokkan, hanya pengingat.', 'example' => 'Budi Santoso'],
            'tanggal_jam' => ['label' => 'Tanggal & jam', 'hint' => 'Mis. 03/11/2026 07:58. Atau isi kolom Tanggal dan Jam terpisah.', 'example' => '03/11/2026 07:58', 'text' => true],
            'tanggal' => ['label' => 'Tanggal', 'hint' => 'Dipakai bila Tanggal & jam kosong.', 'example' => '', 'text' => true],
            'jam' => ['label' => 'Jam', 'hint' => 'Dipakai bersama kolom Tanggal.', 'example' => '', 'text' => true],
        ];
    }

    public function prepare(): void
    {
        $this->byPin = [];
        foreach (Employee::query()->withoutGlobalScopes()->whereNull('deleted_at')->get(['id', 'code', 'name', 'attendance_pin', 'user_id', 'branch_id']) as $employee) {
            $this->byPin[mb_strtolower($employee->code)] ??= $employee;
            if ($employee->attendance_pin !== null) {
                // PIN mesin lebih diutamakan daripada kode karyawan.
                $this->byPin[mb_strtolower($employee->attendance_pin)] = $employee;
            }
        }
        $this->seen = [];
    }

    public function validate(array $row, int $line): array
    {
        $errors = [];
        $pin = $this->text($row['pin'] ?? null);
        $employee = $pin !== null ? ($this->byPin[mb_strtolower($pin)] ?? null) : null;
        $time = $this->scanTime($row);

        if ($pin === null) {
            $errors[] = 'PIN wajib diisi.';
        } elseif ($employee === null) {
            $errors[] = "PIN {$pin} tidak cocok dengan karyawan mana pun. Isi PIN mesin absen di data karyawan.";
        }

        if ($time === null) {
            $errors[] = 'Tanggal & jam kosong atau formatnya tidak dikenali (contoh 03/11/2026 07:58).';
        } elseif ($time->isAfter(now()->addMinutes(5))) {
            $errors[] = 'Waktu scan '.$time->format('d/m/Y H:i').' belum terjadi. Periksa jam mesin.';
        }

        if ($employee !== null && $time !== null) {
            $key = $employee->id.'|'.$time->format('Y-m-d H:i');
            // Scan ganda dalam menit yang sama (jari ditempel dua kali) cukup dicatat sekali.
            if (isset($this->seen[$key])) {
                $errors[] = "Scan ganda (sama dengan baris {$this->seen[$key]}).";
            }
            $this->seen[$key] ??= $line;
        }

        return [['employee' => $employee, 'time' => $time], $errors];
    }

    public function persist(array $data, User $user): string
    {
        return $this->attendance->catatScan($data['employee'], $data['time'], $user);
    }

    private function scanTime(array $row): ?CarbonImmutable
    {
        if (($value = $row['tanggal_jam'] ?? null) !== null && $value !== '') {
            return $this->dateTime($value);
        }

        $date = $this->date($row['tanggal'] ?? null);
        $time = $this->time($row['jam'] ?? null);

        return $date && $time ? $date->setTimeFromTimeString($time) : null;
    }
}
