<?php

namespace App\Services\Sdm;

use App\Enums\EmployeeMovementType;
use App\Exceptions\AturanBisnisException;
use App\Models\Employee;
use App\Models\EmployeeMovement;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mutasi, promosi, dan demosi. Posisi sebelumnya diambil dari data karyawan saat dicatat; isian
 * yang dikosongkan berarti tidak berubah. Tanggal efektif hari ini atau lewat langsung diterapkan,
 * tanggal depan diterapkan perintah sdm:proses-harian (applyDue).
 */
class EmployeeMovementService
{
    public function __construct(private readonly EmployeeService $employees) {}

    /** @param array<string, mixed> $data */
    public function record(Employee $employee, array $data, User $user): EmployeeMovement
    {
        return DB::transaction(function () use ($employee, $data, $user) {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($locked->movements()->whereNull('applied_at')->exists()) {
                throw new AturanBisnisException("{$locked->name} masih punya mutasi/promosi yang menunggu tanggal efektif. Batalkan atau tunggu dulu.");
            }

            $movement = $locked->movements()->make([
                'type' => EmployeeMovementType::from($data['type'])->value,
                'effective_date' => $data['effective_date'],
                'decree_number' => $data['decree_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $changed = false;
            foreach (EmployeeMovement::FIELDS as $field) {
                $from = $locked->getAttribute($field);
                $to = array_key_exists("to_{$field}", $data) && $data["to_{$field}"] !== null && $data["to_{$field}"] !== '' ? $data["to_{$field}"] : $from;
                $movement->setAttribute("from_{$field}", $from);
                $movement->setAttribute("to_{$field}", $to);
                $changed = $changed || (string) $from !== (string) $to;
            }
            if (! $changed) {
                throw new AturanBisnisException('Isi minimal satu perubahan: cabang, departemen, jabatan, atasan, atau grade.');
            }
            if ($movement->to_supervisor_id !== null && in_array((int) $movement->to_supervisor_id, [$locked->id, ...$this->employees->descendantIds($locked)], true)) {
                throw new AturanBisnisException('Atasan baru tidak boleh karyawan itu sendiri atau bawahannya.');
            }
            $movement->created_by = $user->id;
            $movement->save();

            activity('sdm')->performedOn($locked)->causedBy($user)->event('movement_recorded')
                ->withProperties(['jenis' => $movement->type->label(), 'efektif' => $movement->effective_date->toDateString()])
                ->log("{$movement->type->label()} {$locked->name} efektif ".Format::tanggal($movement->effective_date));

            if (! CarbonImmutable::parse($movement->effective_date)->isFuture()) {
                $this->apply($movement);
            }

            return $movement;
        });
    }

    /** Batalkan mutasi yang belum diterapkan. */
    public function cancel(EmployeeMovement $movement, User $user): void
    {
        if ($movement->isApplied()) {
            throw new AturanBisnisException('Mutasi yang sudah diterapkan tidak bisa dibatalkan. Catat mutasi baru untuk mengembalikannya.');
        }
        $movement->delete();
        activity('sdm')->performedOn($movement->employee)->causedBy($user)->event('movement_cancelled')
            ->log("{$movement->type->label()} {$movement->employee->name} yang menunggu tanggal efektif dibatalkan");
    }

    /** Terapkan semua mutasi yang tanggal efektifnya sudah tiba. Mengembalikan jumlahnya. */
    public function applyDue(): int
    {
        $due = EmployeeMovement::query()->whereNull('applied_at')->whereDate('effective_date', '<=', CarbonImmutable::today()->toDateString())->get();
        foreach ($due as $movement) {
            DB::transaction(fn () => $this->apply($movement));
        }

        return $due->count();
    }

    private function apply(EmployeeMovement $movement): void
    {
        $employee = Employee::query()->whereKey($movement->employee_id)->lockForUpdate()->firstOrFail();
        $employee->forceFill(collect(EmployeeMovement::FIELDS)->mapWithKeys(fn ($f) => [$f => $movement->getAttribute("to_{$f}")])->all())->save();
        $movement->forceFill(['applied_at' => now()])->save();
    }
}
