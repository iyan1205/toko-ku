<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasApproval;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasDocumentStatus;
use App\Models\Concerns\IsEmployeeRequest;
use App\Models\Concerns\TracksAuthors;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pengajuan penggantian biaya (reimbursement) dengan bukti/struk sebagai lampiran. Dibayar lewat penggajian (Fase 14). */
class ReimbursementRequest extends Model
{
    use BelongsToBranch, HasApproval, HasAttachments, HasDocumentStatus, IsEmployeeRequest, SoftDeletes, TracksAuthors;

    protected $fillable = ['expense_date', 'category', 'amount', 'reason'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date', 'amount' => 'decimal:2',
            'is_rejected' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function documentType(): DocumentType
    {
        return DocumentType::Reimbursement;
    }

    public function documentDateColumn(): string
    {
        return 'expense_date';
    }

    public function approvalDocumentType(): string
    {
        return 'reimbursement';
    }

    public function approvalTitle(): string
    {
        return "{$this->number} · Reimbursement {$this->employee?->name}: {$this->categoryLabel()}";
    }

    public function approvalAmount(): string
    {
        return (string) $this->amount;
    }

    public function categoryLabel(): string
    {
        return config("sdm.reimbursement_categories.{$this->category}", $this->category);
    }

    /** Lampiran untuk HR: lihat = sdm.reimbursements.view, ubah = sdm.reimbursements.approve. */
    public function attachmentPermission(string $action): ?string
    {
        return $action === 'view' ? 'sdm.reimbursements.view' : 'sdm.reimbursements.approve';
    }

    /** Karyawan pemilik pengajuan boleh melihat buktinya, dan menambah/menghapus selama masih menunggu. */
    public function allowsAttachmentAccess(User $user, string $action): bool
    {
        $owner = $this->employee?->user_id !== null && $this->employee->user_id === $user->id;

        return $owner && ($action === 'view' || $this->isPending());
    }

    protected function hrRouteName(): string
    {
        return 'reimbursements.index';
    }

    /** Pengajuan karyawan mengikuti cabang karyawannya, bukan cabang aktif pengaju. */
    protected function guardsBranchAccess(): bool
    {
        return false;
    }

    public function approvalUrl(): string
    {
        return route($this->hrRouteName(), ['cari' => $this->number]);
    }

    /** Ditolak lewat alur persetujuan: tutup pengajuan (bukan kembali ke draf). */
    public function onApprovalRejected(ApprovalRequest $request, User $by, string $note): void
    {
        $this->forceFill(['is_rejected' => true]);
        $this->moveToStatus(DocumentStatus::Cancelled, $by, mb_substr('Ditolak: '.$note, 0, 255));
    }
}
