<?php

namespace App\Services\Sdm;

use App\Models\Employee;
use App\Services\KodeService;
use Illuminate\Support\Facades\DB;

/** Tambah, ubah, dan hapus karyawan. Kode otomatis KRY-0001 bila tidak diisi. */
class EmployeeService
{
    public const CODE_PREFIX = 'KRY-';

    public function __construct(private readonly KodeService $codes) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Employee
    {
        return DB::transaction(function () use ($data) {
            $data['code'] ??= $this->codes->berikutnya(Employee::class, 'code', self::CODE_PREFIX, 4);

            return Employee::create($data);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Employee $employee, array $data): Employee
    {
        // Kode yang dikosongkan saat mengubah tetap memakai kode lama.
        $data['code'] ??= $employee->code;
        $employee->update($data);

        return $employee;
    }

    /** Hapus lunak; tautan akun login dilepas supaya akun itu bisa dipakai karyawan lain. */
    public function delete(Employee $employee): void
    {
        DB::transaction(function () use ($employee) {
            $employee->forceFill(['user_id' => null])->save();
            $employee->delete();
        });
    }
}
