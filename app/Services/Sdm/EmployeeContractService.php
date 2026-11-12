<?php

namespace App\Services\Sdm;

use App\Enums\EmploymentType;
use App\Exceptions\AturanBisnisException;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Riwayat kontrak kerja. Kontrak baru harus dimulai setelah kontrak sebelumnya dimulai; status
 * kerja dan tanggal mulai kerja karyawan mengikuti kontrak terbaru.
 */
class EmployeeContractService
{
    /** @param array{number?: ?string, employment_type: string, start_date: string, end_date?: ?string, notes?: ?string} $data */
    public function add(Employee $employee, array $data, User $user): EmployeeContract
    {
        $type = EmploymentType::from($data['employment_type']);
        $start = CarbonImmutable::parse($data['start_date']);
        $end = ! empty($data['end_date']) ? CarbonImmutable::parse($data['end_date']) : null;

        if ($type === EmploymentType::Contract && $end === null) {
            throw new AturanBisnisException('Kontrak PKWT wajib punya tanggal berakhir.');
        }
        if ($end !== null && $end->lt($start)) {
            throw new AturanBisnisException('Tanggal berakhir kontrak tidak boleh sebelum tanggal mulai.');
        }

        return DB::transaction(function () use ($employee, $data, $type, $start, $end, $user) {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $latest = $locked->contracts()->first();
            if ($latest !== null && ! $start->gt(CarbonImmutable::parse($latest->start_date))) {
                throw new AturanBisnisException('Kontrak baru harus dimulai setelah '.Format::tanggal($latest->start_date).' (mulai kontrak terakhir).');
            }

            $contract = $locked->contracts()->make([
                'number' => $data['number'] ?? null, 'employment_type' => $type->value,
                'start_date' => $start->toDateString(), 'end_date' => $end?->toDateString(), 'notes' => $data['notes'] ?? null,
            ]);
            $contract->created_by = $user->id;
            $contract->save();

            $locked->forceFill(array_filter([
                'employment_type' => $type->value,
                'join_date' => $locked->join_date === null ? $start->toDateString() : null,
            ]))->save();

            activity('sdm')->performedOn($locked)->causedBy($user)->event('contract_added')
                ->withProperties(['jenis' => $type->label(), 'mulai' => $start->toDateString(), 'berakhir' => $end?->toDateString()])
                ->log("Kontrak {$type->label()} {$locked->name}: {$contract->periodLabel()}");

            return $contract;
        });
    }

    /** Hanya kontrak terbaru yang bisa dihapus (salah input); status kerja kembali ke kontrak sebelumnya. */
    public function delete(EmployeeContract $contract, User $user): void
    {
        DB::transaction(function () use ($contract, $user) {
            $employee = Employee::query()->whereKey($contract->employee_id)->lockForUpdate()->firstOrFail();
            if ($employee->contracts()->value('id') !== $contract->id) {
                throw new AturanBisnisException('Hanya kontrak terbaru yang bisa dihapus.');
            }
            $contract->delete();
            if ($previous = $employee->contracts()->first()) {
                $employee->forceFill(['employment_type' => $previous->employment_type->value])->save();
            }

            activity('sdm')->performedOn($employee)->causedBy($user)->event('contract_deleted')
                ->log("Kontrak {$employee->name} ({$contract->periodLabel()}) dihapus");
        });
    }

    /**
     * Kontrak terbaru karyawan aktif yang berakhir dalam $days hari ke depan atau sudah lewat
     * (sampai 30 hari) tanpa kontrak lanjutan.
     *
     * @return Builder<EmployeeContract>
     */
    public function expiring(int $days): Builder
    {
        $today = CarbonImmutable::today();

        return EmployeeContract::query()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $today->addDays($days)->toDateString())
            ->whereDate('end_date', '>=', $today->subDays(30)->toDateString())
            ->whereHas('employee', fn (Builder $q) => $q->where('is_active', true))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('employee_contracts as newer')
                ->whereColumn('newer.employee_id', 'employee_contracts.employee_id')
                ->whereNull('newer.deleted_at')
                ->whereColumn('newer.start_date', '>', 'employee_contracts.start_date'))
            ->with('employee:id,code,name')
            ->orderBy('end_date');
    }
}
