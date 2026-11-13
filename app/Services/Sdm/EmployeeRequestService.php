<?php

namespace App\Services\Sdm;

use App\Enums\DocumentStatus;
use App\Enums\StatusAbsensi;
use App\Exceptions\AturanBisnisException;
use App\Models\Absensi;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\ReimbursementRequest;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use App\Services\Documents\AttachmentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Pengajuan lembur, reimbursement, dan koreksi absensi dari Layanan Mandiri.
 *
 *   - Diajukan langsung (tanpa draf). Bila ada alur di Pengaturan → Alur Persetujuan yang cocok,
 *     pengajuan masuk ApprovalService (diputuskan di Persetujuan Saya); bila tidak, pemegang izin
 *     sdm.overtime.approve / sdm.reimbursements.approve / sdm.attendance-corrections.approve memutuskannya langsung di daftar SDM.
 *   - Karyawan bisa membatalkan pengajuannya selama masih menunggu.
 *   - Cabang dokumen = cabang penempatan karyawan.
 */
class EmployeeRequestService
{
    /** Lembur paling lama dalam satu pengajuan. */
    public const MAX_OVERTIME_MINUTES = 16 * 60;

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AttachmentService $attachments,
    ) {}

    /** @param array{overtime_date: string, start_time: string, end_time: string, reason: string} $data */
    public function submitOvertime(Employee $employee, array $data, User $user): OvertimeRequest
    {
        $date = CarbonImmutable::parse($data['overtime_date']);
        $start = $date->setTimeFromTimeString($data['start_time']);
        $end = $date->setTimeFromTimeString($data['end_time']);
        if ($end->lte($start)) {
            $end = $end->addDay(); // melewati tengah malam
        }
        $minutes = (int) $start->diffInMinutes($end);
        if ($minutes > self::MAX_OVERTIME_MINUTES) {
            throw new AturanBisnisException('Lembur dalam satu pengajuan paling lama 16 jam.');
        }

        return DB::transaction(function () use ($employee, $data, $user, $date, $start, $end, $minutes) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $overlap = OvertimeRequest::withoutGlobalScope(BranchScope::class)->where('employee_id', $employee->id)
                ->whereIn('status', [DocumentStatus::Submitted->value, DocumentStatus::Approved->value])
                ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
            if ($overlap) {
                throw new AturanBisnisException('Sudah ada pengajuan lembur di jam yang sama.');
            }

            $request = new OvertimeRequest([
                'overtime_date' => $date->toDateString(), 'starts_at' => $start, 'ends_at' => $end,
                'duration_minutes' => $minutes, 'reason' => $data['reason'],
            ]);

            return $this->submit($request, $employee, $user);
        });
    }

    /**
     * @param  array{expense_date: string, category: string, amount: string, reason: string}  $data
     * @param  list<UploadedFile>  $receipts
     */
    public function submitReimbursement(Employee $employee, array $data, array $receipts, User $user): ReimbursementRequest
    {
        return DB::transaction(function () use ($employee, $data, $receipts, $user) {
            $request = new ReimbursementRequest([
                'expense_date' => $data['expense_date'], 'category' => $data['category'],
                'amount' => $data['amount'], 'reason' => $data['reason'],
            ]);
            $request = $this->submit($request, $employee, $user);
            foreach ($receipts as $file) {
                $this->attachments->store($request, $file, $user, 'Bukti pengeluaran');
            }

            return $request;
        });
    }

    /**
     * Koreksi absensi sendiri (lupa absen masuk/pulang, jam mesin salah). Paling lama
     * setting sdm.attendance.correction_days hari ke belakang (bawaan 30).
     *
     * @param  array{attendance_date: string, clock_in?: ?string, clock_out?: ?string, reason: string}  $data
     */
    public function submitCorrection(Employee $employee, array $data, User $user): AttendanceCorrection
    {
        $date = CarbonImmutable::parse($data['attendance_date'])->startOfDay();
        $maxDays = (int) setting('sdm.attendance.correction_days', 30);
        if ($date->isAfter(today())) {
            throw new AturanBisnisException('Koreksi hanya untuk tanggal yang sudah lewat atau hari ini.');
        }
        if ($date->lt(today()->subDays($maxDays))) {
            throw new AturanBisnisException("Koreksi absensi paling lama {$maxDays} hari ke belakang. Hubungi bagian SDM.");
        }
        $in = filled($data['clock_in'] ?? null) ? $date->setTimeFromTimeString($data['clock_in']) : null;
        $out = filled($data['clock_out'] ?? null) ? $date->setTimeFromTimeString($data['clock_out']) : null;
        if ($in === null && $out === null) {
            throw new AturanBisnisException('Isi jam masuk, jam pulang, atau keduanya.');
        }
        if ($in !== null && $out !== null && $out->lte($in)) {
            $out = $out->addDay(); // shift malam: pulang keesokan harinya
        }

        return DB::transaction(function () use ($employee, $data, $user, $date, $in, $out) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $pending = AttendanceCorrection::withoutGlobalScope(BranchScope::class)->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $date->toDateString())->where('status', DocumentStatus::Submitted->value)->exists();
            if ($pending) {
                throw new AturanBisnisException('Sudah ada koreksi yang menunggu persetujuan untuk tanggal ini.');
            }

            $absensi = Absensi::query()
                ->where(fn ($q) => $q->where('employee_id', $employee->id)->when($employee->user_id, fn ($w, $id) => $w->orWhere('user_id', $id)))
                ->whereDate('tanggal', $date->toDateString())->first();
            if ($absensi !== null && ! $absensi->status->pakaiJam() && $absensi->status !== StatusAbsensi::Alpa) {
                throw new AturanBisnisException("Tanggal ini tercatat {$absensi->status->label()}. Hubungi bagian SDM bila catatan itu salah.");
            }
            $finalIn = $in ?? $absensi?->clock_in;
            $finalOut = $out ?? $absensi?->clock_out;
            if ($finalIn !== null && $finalOut !== null && CarbonImmutable::parse($finalOut)->lte(CarbonImmutable::parse($finalIn))) {
                throw new AturanBisnisException('Jam pulang harus setelah jam masuk.');
            }

            $request = new AttendanceCorrection([
                'attendance_date' => $date->toDateString(), 'absensi_id' => $absensi?->id,
                'original_status' => $absensi?->status->value, 'original_clock_in' => $absensi?->clock_in, 'original_clock_out' => $absensi?->clock_out,
                'requested_clock_in' => $in, 'requested_clock_out' => $out, 'reason' => $data['reason'],
            ]);

            return $this->submit($request, $employee, $user);
        });
    }

    /** Setujui langsung (tanpa alur persetujuan). */
    public function approve(OvertimeRequest|ReimbursementRequest|AttendanceCorrection $request, User $user): Model
    {
        return DB::transaction(function () use ($request, $user) {
            $locked = $this->lockPending($request);
            if ($locked->pendingApproval() !== null) {
                throw new AturanBisnisException("{$locked->number} sedang di alur persetujuan. Putuskan lewat Persetujuan Saya.");
            }
            $locked->moveToStatus(DocumentStatus::Approved, $user);
            $this->log($locked, $user, 'approved', 'disetujui');

            return $locked;
        });
    }

    /** Tolak langsung (tanpa alur persetujuan). */
    public function reject(OvertimeRequest|ReimbursementRequest|AttendanceCorrection $request, User $user, string $note): Model
    {
        return DB::transaction(function () use ($request, $user, $note) {
            $locked = $this->lockPending($request);
            if ($locked->pendingApproval() !== null) {
                throw new AturanBisnisException("{$locked->number} sedang di alur persetujuan. Putuskan lewat Persetujuan Saya.");
            }
            $locked->forceFill(['is_rejected' => true]);
            $locked->moveToStatus(DocumentStatus::Cancelled, $user, mb_substr('Ditolak: '.$note, 0, 255));
            $this->log($locked, $user, 'rejected', 'ditolak', ['alasan' => $note]);

            return $locked;
        });
    }

    /** Dibatalkan karyawan sendiri selama masih menunggu. */
    public function cancel(OvertimeRequest|ReimbursementRequest|AttendanceCorrection $request, User $user): Model
    {
        return DB::transaction(function () use ($request, $user) {
            $locked = $this->lockPending($request);
            if (($pending = $locked->pendingApproval()) !== null) {
                $this->approvals->cancel($pending, $user, 'Dibatalkan pengaju', force: true);
                $locked->refresh();
            }
            if ($locked->documentStatus() !== DocumentStatus::Cancelled) {
                $locked->moveToStatus(DocumentStatus::Cancelled, $user, 'Dibatalkan pengaju');
            }
            $this->log($locked, $user, 'cancelled', 'dibatalkan pengaju');

            return $locked;
        });
    }

    private function submit(OvertimeRequest|ReimbursementRequest|AttendanceCorrection $request, Employee $employee, User $user): Model
    {
        if (! $employee->is_active) {
            throw new AturanBisnisException('Karyawan tidak aktif tidak bisa mengajukan.');
        }
        $request->employee_id = $employee->id;
        $request->branch_id = $employee->branch_id ?? null;
        $request->created_by = $user->id;
        $request->assignDocumentNumber();
        $request->save();

        if ($this->approvals->requiresApproval($request)) {
            $this->approvals->submit($request, $user);
        } else {
            $request->moveToStatus(DocumentStatus::Submitted, $user);
        }
        $this->log($request, $user, 'submitted', 'diajukan');

        return $request->refresh();
    }

    private function lockPending(OvertimeRequest|ReimbursementRequest|AttendanceCorrection $request): Model
    {
        $locked = $request::withoutGlobalScope(BranchScope::class)->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isPending()) {
            throw new AturanBisnisException("{$locked->number} sudah {$locked->statusLabel()}.");
        }

        return $locked;
    }

    /** @param array<string, mixed> $properties */
    private function log(Model $request, User $user, string $event, string $verb, array $properties = []): void
    {
        $kind = match (true) {
            $request instanceof OvertimeRequest => 'Lembur',
            $request instanceof AttendanceCorrection => 'Koreksi absensi',
            default => 'Reimbursement',
        };
        activity('sdm')->performedOn($request)->causedBy($user)->event("request_{$event}")->withProperties($properties)
            ->log("{$kind} {$request->number} {$verb}");
    }
}
