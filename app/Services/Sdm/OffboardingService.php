<?php

namespace App\Services\Sdm;

use App\Exceptions\AturanBisnisException;
use App\Models\Employee;
use App\Models\EmployeeOffboarding;
use App\Models\EmployeeOffboardingItem;
use App\Models\FixedAsset;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Offboarding (resign/PHK/kontrak berakhir/pensiun):
 *   start()     checklist dari config('sdm.offboarding_checklist') + aset tetap yang dipegang karyawan
 *   toggle()    tandai barang sudah dikembalikan/selesai
 *   complete()  semua checklist harus selesai; tanggal & alasan berhenti dicatat di karyawan, aset yang
 *               dikembalikan dilepas dari karyawan, lalu karyawan + akun login dinonaktifkan pada hari
 *               terakhir kerja (hari ini atau lewat: langsung; tanggal depan: sdm:proses-harian)
 *   cancel()    hanya selama berjalan
 */
class OffboardingService
{
    /** @param array{termination_type: string, notice_date: string, last_working_date: string, reason?: ?string, deactivate_user?: bool} $data */
    public function start(Employee $employee, array $data, User $user): EmployeeOffboarding
    {
        return DB::transaction(function () use ($employee, $data, $user) {
            $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_active) {
                throw new AturanBisnisException("{$locked->name} sudah tidak aktif.");
            }
            if ($locked->offboardings()->where('status', EmployeeOffboarding::IN_PROGRESS)->exists()) {
                throw new AturanBisnisException("Offboarding {$locked->name} sudah berjalan.");
            }

            $offboarding = $locked->offboardings()->make($data + ['deactivate_user' => true]);
            $offboarding->forceFill(['status' => EmployeeOffboarding::IN_PROGRESS, 'created_by' => $user->id])->save();

            foreach (config('sdm.offboarding_checklist', []) as $label) {
                $offboarding->items()->create(['label' => $label]);
            }
            foreach ($locked->fixedAssets()->where('status', FixedAsset::ACTIVE)->orderBy('code')->get() as $asset) {
                $offboarding->items()->create(['label' => "Aset {$asset->code} {$asset->name}", 'fixed_asset_id' => $asset->id]);
            }

            activity('sdm')->performedOn($locked)->causedBy($user)->event('offboarding_started')
                ->withProperties(['alasan' => $offboarding->termination_type->label(), 'hari_terakhir' => $offboarding->last_working_date->toDateString()])
                ->log("Offboarding {$locked->name} dimulai ({$offboarding->termination_type->label()}, hari terakhir ".Format::tanggal($offboarding->last_working_date).')');

            return $offboarding;
        });
    }

    public function addItem(EmployeeOffboarding $offboarding, string $label): EmployeeOffboardingItem
    {
        $this->ensureInProgress($offboarding);

        return $offboarding->items()->create(['label' => $label]);
    }

    public function toggle(EmployeeOffboardingItem $item, bool $done, User $user, ?string $note = null): EmployeeOffboardingItem
    {
        $this->ensureInProgress($item->offboarding);
        $item->forceFill([
            'is_done' => $done,
            'done_at' => $done ? now() : null,
            'done_by' => $done ? $user->id : null,
            'note' => $note !== null ? mb_substr($note, 0, 255) : $item->note,
        ])->save();

        return $item;
    }

    public function complete(EmployeeOffboarding $offboarding, User $user): EmployeeOffboarding
    {
        return DB::transaction(function () use ($offboarding, $user) {
            $locked = EmployeeOffboarding::query()->whereKey($offboarding->id)->lockForUpdate()->firstOrFail();
            $this->ensureInProgress($locked);
            $open = $locked->items()->where('is_done', false)->count();
            if ($open > 0) {
                throw new AturanBisnisException("Masih ada {$open} hal di checklist yang belum selesai.");
            }

            $employee = Employee::query()->whereKey($locked->employee_id)->lockForUpdate()->firstOrFail();
            $employee->forceFill([
                'end_date' => $locked->last_working_date->toDateString(),
                'termination_type' => $locked->termination_type->value,
                'termination_reason' => $locked->reason,
            ])->save();

            $assetIds = $locked->items()->whereNotNull('fixed_asset_id')->pluck('fixed_asset_id');
            FixedAsset::withoutGlobalScope(BranchScope::class)->whereIn('id', $assetIds)->where('employee_id', $employee->id)
                ->update(['employee_id' => null, 'holder_name' => null]);

            $locked->forceFill(['status' => EmployeeOffboarding::COMPLETED, 'completed_at' => now(), 'completed_by' => $user->id])->save();

            activity('sdm')->performedOn($employee)->causedBy($user)->event('offboarding_completed')
                ->log("Offboarding {$employee->name} selesai");

            if (! CarbonImmutable::parse($locked->last_working_date)->isFuture()) {
                $this->deactivate($locked);
            }

            return $locked;
        });
    }

    public function cancel(EmployeeOffboarding $offboarding, User $user, string $reason): EmployeeOffboarding
    {
        return DB::transaction(function () use ($offboarding, $user, $reason) {
            $locked = EmployeeOffboarding::query()->whereKey($offboarding->id)->lockForUpdate()->firstOrFail();
            $this->ensureInProgress($locked);
            $locked->forceFill(['status' => EmployeeOffboarding::CANCELLED, 'cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => mb_substr($reason, 0, 255)])->save();
            activity('sdm')->performedOn($locked->employee)->causedBy($user)->event('offboarding_cancelled')
                ->withProperties(['alasan' => $reason])->log("Offboarding {$locked->employee->name} dibatalkan");

            return $locked;
        });
    }

    /** Nonaktifkan karyawan + akun dari offboarding selesai yang hari terakhirnya sudah lewat. */
    public function deactivateDue(): int
    {
        $due = EmployeeOffboarding::query()->where('status', EmployeeOffboarding::COMPLETED)->whereNull('deactivated_at')
            ->whereDate('last_working_date', '<=', CarbonImmutable::today()->toDateString())->get();
        foreach ($due as $offboarding) {
            DB::transaction(fn () => $this->deactivate($offboarding));
        }

        return $due->count();
    }

    private function deactivate(EmployeeOffboarding $offboarding): void
    {
        $employee = Employee::withTrashed()->whereKey($offboarding->employee_id)->lockForUpdate()->firstOrFail();
        $employee->forceFill(['is_active' => false])->save();

        if ($offboarding->deactivate_user && $employee->user_id !== null) {
            $account = User::query()->whereKey($employee->user_id)->first();
            if ($account?->is_active) {
                $account->forceFill(['is_active' => false])->save();
                activity('hak-akses')->performedOn($account)->event('user_deactivated')
                    ->withProperties(['karyawan' => $employee->code])->log("Akun {$account->name} dinonaktifkan karena offboarding");
            }
        }
        $offboarding->forceFill(['deactivated_at' => now()])->save();
    }

    private function ensureInProgress(EmployeeOffboarding $offboarding): void
    {
        if (! $offboarding->isInProgress()) {
            throw new AturanBisnisException("Offboarding ini sudah {$offboarding->statusLabel()}.");
        }
    }
}
