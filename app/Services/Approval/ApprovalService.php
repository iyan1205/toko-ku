<?php

namespace App\Services\Approval;

use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\ApproverType;
use App\Exceptions\AturanBisnisException;
use App\Models\ApprovalFlow;
use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\Concerns\HasApproval;
use App\Models\User;
use App\Notifications\ApprovalDecided;
use App\Notifications\ApprovalRequested;
use App\Support\CurrentBranch;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Mesin persetujuan berjenjang.
 *
 *   1. flowFor(): alur aktif untuk jenis dokumen, cabang dokumen (atau semua cabang), dan
 *      kondisi yang cocok; prioritas terkecil menang, alur khusus cabang didahulukan.
 *   2. submit(): tahap alur disalin ke pengajuan; penyetuju tahap 1 diberi notifikasi.
 *   3. approve(): tahap berikutnya, atau selesai (dokumen disetujui, pengaju diberi notifikasi).
 *      reject(): pengajuan berhenti, dokumen kembali bisa diubah (wajib alasan).
 *      cancel(): pengaju menarik pengajuannya (atau dokumennya dibatalkan).
 *
 * Penyetuju satu tahap: pengguna aktif dengan peran tahap itu, atau pengguna tertentu, yang boleh
 * mengakses cabang dokumen. Pengaju tidak boleh menyetujui pengajuannya sendiri kecuali alurnya
 * mengizinkan. Setiap langkah tercatat di tabel approvals dan log aktivitas "persetujuan".
 */
class ApprovalService
{
    public function __construct(private readonly CurrentBranch $branches) {}

    public function flowFor(Model $document): ?ApprovalFlow
    {
        $this->ensureApprovable($document);

        return ApprovalFlow::query()->active()
            ->with('steps')
            ->where('document_type', $document->approvalDocumentType())
            ->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $document->getAttribute('branch_id')))
            ->orderBy('priority')
            ->orderByRaw('case when branch_id is null then 1 else 0 end')
            ->orderBy('id')
            ->get()
            ->first(fn (ApprovalFlow $flow) => $flow->matches(fn (string $field) => $document->approvalValue($field)));
    }

    public function requiresApproval(Model $document): bool
    {
        return $this->flowFor($document) !== null;
    }

    /** Ajukan dokumen ke alur yang cocok. Dipanggil di dalam/luar transaksi penyimpan dokumen. */
    public function submit(Model $document, User $by, ?string $note = null): ApprovalRequest
    {
        $flow = $this->flowFor($document) ?? throw new AturanBisnisException('Dokumen ini tidak memerlukan persetujuan.');
        $steps = $this->snapshot($flow);

        return DB::transaction(function () use ($document, $by, $note, $flow, $steps) {
            if ($document->pendingApproval() !== null) {
                throw new AturanBisnisException("{$document->approvalTitle()} sudah diajukan dan sedang menunggu persetujuan.");
            }

            $request = new ApprovalRequest;
            $request->forceFill([
                'approval_flow_id' => $flow->getKey(),
                'approvable_type' => $document->getMorphClass(),
                'approvable_id' => $document->getKey(),
                'document_type' => $document->approvalDocumentType(),
                'title' => mb_substr($document->approvalTitle(), 0, 255),
                'amount' => ($amount = $document->approvalAmount()) !== null ? Uang::keDesimal(Uang::sen($amount)) : null,
                'branch_id' => $document->getAttribute('branch_id'),
                'status' => ApprovalStatus::Pending,
                'steps' => $steps,
                'allow_self_approval' => $flow->allow_self_approval,
                'submitted_by' => $by->getKey(),
                'submitted_at' => now(),
                'note' => $this->note($note),
            ]);
            $this->moveTo($request, $steps[0]);
            $request->save();

            $this->record($request, $by, ApprovalAction::Submitted, null, $request->note);
            $document->onApprovalSubmitted($request);

            activity('persetujuan')->performedOn($document)->causedBy($by)->event('approval_submitted')
                ->withProperties(['alur' => $flow->name, 'tahap' => count($steps)])
                ->log("{$request->title} diajukan untuk persetujuan ({$flow->name})");

            $this->notifyApprovers($request);

            return $request;
        });
    }

    public function approve(ApprovalRequest $request, User $by, ?string $note = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $by, $note) {
            $locked = $this->lockPending($request);
            $this->ensureCanAct($locked, $by);

            $step = $locked->currentStep();
            $this->record($locked, $by, ApprovalAction::Approved, $step, $this->note($note));

            $next = collect($locked->steps)->first(fn (array $s) => $s['sequence'] > $step['sequence']);
            $document = $locked->approvable;

            if ($next !== null) {
                $this->moveTo($locked, $next);
                $locked->save();

                activity('persetujuan')->performedOn($document)->causedBy($by)->event('approval_step_approved')
                    ->withProperties(['tahap' => $step['name']])
                    ->log("{$locked->title} disetujui tahap {$step['sequence']} ({$step['name']})");

                $this->notifyApprovers($locked);

                return $locked;
            }

            $locked->forceFill(['status' => ApprovalStatus::Approved, 'completed_at' => now(), 'current_step' => null, 'current_role_id' => null, 'current_user_id' => null])->save();
            $document->onApprovalApproved($locked, $by);

            activity('persetujuan')->performedOn($document)->causedBy($by)->event('approval_approved')
                ->log("{$locked->title} disetujui");

            $this->notifySubmitter($locked, $by, $note);

            return $locked;
        });
    }

    public function reject(ApprovalRequest $request, User $by, string $note): ApprovalRequest
    {
        $note = trim($note);
        if (mb_strlen($note) < 5) {
            throw ValidationException::withMessages(['alasan' => 'Alasan penolakan minimal 5 karakter.']);
        }

        return DB::transaction(function () use ($request, $by, $note) {
            $locked = $this->lockPending($request);
            $this->ensureCanAct($locked, $by);

            $this->record($locked, $by, ApprovalAction::Rejected, $locked->currentStep(), $note);
            $locked->forceFill(['status' => ApprovalStatus::Rejected, 'completed_at' => now(), 'current_role_id' => null, 'current_user_id' => null])->save();
            $locked->approvable->onApprovalRejected($locked, $by, $note);

            activity('persetujuan')->performedOn($locked->approvable)->causedBy($by)->event('approval_rejected')
                ->withProperties(['alasan' => $note])
                ->log("{$locked->title} ditolak");

            $this->notifySubmitter($locked, $by, $note);

            return $locked;
        });
    }

    /**
     * Tarik pengajuan: oleh pengaju sendiri (atau oleh sistem saat dokumennya dibatalkan, $by = pembatal).
     * $force = true dipakai service dokumen saat membatalkan dokumennya.
     */
    public function cancel(ApprovalRequest $request, User $by, ?string $note = null, bool $force = false): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $by, $note, $force) {
            $locked = $this->lockPending($request);

            if (! $force && $locked->submitted_by !== $by->getKey()) {
                throw new AturanBisnisException('Hanya pengaju yang bisa menarik pengajuan ini.');
            }

            $this->record($locked, $by, ApprovalAction::Cancelled, $locked->currentStep(), $this->note($note));
            $locked->forceFill(['status' => ApprovalStatus::Cancelled, 'completed_at' => now(), 'current_role_id' => null, 'current_user_id' => null])->save();
            $locked->approvable->onApprovalCancelled($locked, $by);

            activity('persetujuan')->performedOn($locked->approvable)->causedBy($by)->event('approval_cancelled')
                ->log("Pengajuan {$locked->title} ditarik");

            return $locked;
        });
    }

    /** Pengguna boleh menyetujui/menolak tahap yang sedang menunggu. */
    public function canAct(ApprovalRequest $request, User $user): bool
    {
        return $this->whyCannotAct($request, $user) === null;
    }

    public function whyCannotAct(ApprovalRequest $request, User $user): ?string
    {
        if (! $request->isPending()) {
            return 'Pengajuan ini sudah selesai.';
        }
        if (! $request->allow_self_approval && $request->submitted_by === $user->getKey()) {
            return 'Anda yang mengajukan dokumen ini, jadi persetujuannya harus oleh pengguna lain.';
        }
        if (! $this->isApprover($user, $request->current_role_id, $request->current_user_id)) {
            return 'Anda bukan penyetuju tahap ini.';
        }
        if ($request->branch_id !== null && ! $this->canAccessBranch($user, $request->branch_id)) {
            return 'Dokumen ini milik cabang yang tidak bisa Anda akses.';
        }

        return null;
    }

    /** Kotak masuk: pengajuan yang menunggu tindakan pengguna ini. */
    public function inbox(User $user): Builder
    {
        $roleIds = $user->roles->modelKeys();
        $branchIds = $this->branches->accessibleIds($user);

        return ApprovalRequest::query()->pending()
            ->where(fn (Builder $q) => $q
                ->where('current_user_id', $user->getKey())
                ->when($roleIds !== [], fn (Builder $w) => $w->orWhereIn('current_role_id', $roleIds))
                ->when($user->isSuperAdmin(), fn (Builder $w) => $w->orWhereNotNull('current_step')))
            ->where(fn (Builder $q) => $q->where('allow_self_approval', true)->orWhere('submitted_by', '!=', $user->getKey()))
            ->when($branchIds !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $branchIds)));
    }

    /**
     * Penyetuju tahap yang sedang menunggu (untuk notifikasi).
     *
     * @return Collection<int, User>
     */
    public function approvers(ApprovalRequest $request): Collection
    {
        $users = $request->current_user_id !== null
            ? User::query()->aktif()->whereKey($request->current_user_id)->get()
            : User::query()->aktif()->whereHas('roles', fn (Builder $q) => $q->whereKey($request->current_role_id))->get();

        return $users->filter(fn (User $user) => $this->canAct($request, $user))->values();
    }

    /** @return list<array{sequence: int, name: string, approver_type: string, role_id: ?int, role_name: ?string, user_id: ?int, user_name: ?string}> */
    private function snapshot(ApprovalFlow $flow): array
    {
        if ($flow->steps->isEmpty()) {
            throw new AturanBisnisException("Alur persetujuan \"{$flow->name}\" belum punya tahap. Lengkapi di Pengaturan → Alur Persetujuan.");
        }

        return $flow->steps->map(function (ApprovalStep $step) use ($flow) {
            if ($step->approverLabel() === null) {
                throw new AturanBisnisException("Alur persetujuan \"{$flow->name}\" tahap {$step->sequence} ({$step->name}) belum punya penyetuju. Perbaiki di Pengaturan → Alur Persetujuan.");
            }

            return [
                'sequence' => $step->sequence,
                'name' => $step->name,
                'approver_type' => $step->approver_type->value,
                'role_id' => $step->approver_type === ApproverType::Role ? $step->role_id : null,
                'role_name' => $step->approver_type === ApproverType::Role ? $step->role?->name : null,
                'user_id' => $step->approver_type === ApproverType::User ? $step->user_id : null,
                'user_name' => $step->approver_type === ApproverType::User ? $step->user?->name : null,
            ];
        })->values()->all();
    }

    /** @param array{sequence: int, role_id: ?int, user_id: ?int} $step */
    private function moveTo(ApprovalRequest $request, array $step): void
    {
        $request->forceFill([
            'current_step' => $step['sequence'],
            'current_role_id' => $step['role_id'],
            'current_user_id' => $step['user_id'],
        ]);
    }

    /** @param array{sequence: int, name: string}|null $step */
    private function record(ApprovalRequest $request, User $by, ApprovalAction $action, ?array $step, ?string $note): void
    {
        $request->history()->create([
            'step' => $step['sequence'] ?? null,
            'step_name' => $step['name'] ?? null,
            'user_id' => $by->getKey(),
            'action' => $action,
            'note' => $note,
        ]);
    }

    private function lockPending(ApprovalRequest $request): ApprovalRequest
    {
        $locked = ApprovalRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->isPending()) {
            throw new AturanBisnisException("Pengajuan {$locked->title} sudah {$locked->status->label()}.");
        }

        return $locked;
    }

    private function ensureCanAct(ApprovalRequest $request, User $user): void
    {
        if (($reason = $this->whyCannotAct($request, $user)) !== null) {
            throw new AturanBisnisException($reason);
        }
    }

    private function isApprover(User $user, ?int $roleId, ?int $userId): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $userId !== null
            ? $user->getKey() === $userId
            : $roleId !== null && $user->roles->contains(fn ($role) => $role->getKey() === $roleId);
    }

    private function canAccessBranch(User $user, int $branchId): bool
    {
        $ids = $this->branches->accessibleIds($user);

        return $ids === null || in_array($branchId, $ids, true);
    }

    private function notifyApprovers(ApprovalRequest $request): void
    {
        $request->loadMissing('submitter');
        $approvers = $this->approvers($request);

        if ($approvers->isNotEmpty()) {
            DB::afterCommit(fn () => Notification::send($approvers, new ApprovalRequested($request)));
        }
    }

    private function notifySubmitter(ApprovalRequest $request, User $by, ?string $note): void
    {
        $submitter = $request->submitter()->first();

        if ($submitter !== null && $submitter->is_active && $submitter->isNot($by)) {
            DB::afterCommit(fn () => $submitter->notify(new ApprovalDecided($request, $by, $this->note($note))));
        }
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 255);
    }

    private function ensureApprovable(Model $document): void
    {
        if (! in_array(HasApproval::class, class_uses_recursive($document), true)) {
            throw new InvalidArgumentException($document::class.' tidak memakai HasApproval.');
        }
    }
}
