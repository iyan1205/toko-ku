<?php

namespace App\Models\Concerns;

use App\Enums\ApprovalStatus;
use App\Enums\DocumentStatus;
use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Dokumen yang bisa diberi alur persetujuan (App\Services\Approval\ApprovalService).
 *
 * Model wajib menyediakan approvalDocumentType() (kunci di config/approval.php),
 * approvalTitle(), dan approvalUrl(). Nilai untuk kondisi alur dibaca dari approvalValue();
 * bawaan 'total' = approvalAmount().
 *
 * Kait (hook) dipanggil ApprovalService di dalam transaksi yang sama:
 *   onApprovalSubmitted / Approved / Rejected / Cancelled.
 * Bawaannya mengubah status HasDocumentStatus (submitted, approved, kembali ke draft).
 * Dokumen dengan status sendiri (misal purchase order) menimpa kait ini.
 */
trait HasApproval
{
    abstract public function approvalDocumentType(): string;

    abstract public function approvalTitle(): string;

    abstract public function approvalUrl(): string;

    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequest::class, 'approvable')->latest('id');
    }

    /** Pengajuan terakhir (berjalan atau sudah selesai), untuk panel persetujuan di halaman dokumen. */
    public function latestApproval(): MorphOne
    {
        return $this->morphOne(ApprovalRequest::class, 'approvable')->latestOfMany();
    }

    public function pendingApproval(): ?ApprovalRequest
    {
        return $this->approvalRequests()->where('status', ApprovalStatus::Pending->value)->first();
    }

    /** Nominal dokumen untuk kondisi "total" dan ditampilkan di kotak masuk. */
    public function approvalAmount(): float|int|string|null
    {
        return null;
    }

    public function approvalValue(string $field): mixed
    {
        return $field === 'total' ? $this->approvalAmount() : $this->getAttribute($field);
    }

    public function onApprovalSubmitted(ApprovalRequest $request): void
    {
        if (method_exists($this, 'moveToStatus')) {
            $this->moveToStatus(DocumentStatus::Submitted);
        }
    }

    public function onApprovalApproved(ApprovalRequest $request, User $by): void
    {
        if (method_exists($this, 'moveToStatus')) {
            $this->moveToStatus(DocumentStatus::Approved, $by);
        }
    }

    public function onApprovalRejected(ApprovalRequest $request, User $by, string $note): void
    {
        if (method_exists($this, 'moveToStatus')) {
            $this->moveToStatus(DocumentStatus::Draft);
        }
    }

    public function onApprovalCancelled(ApprovalRequest $request, ?User $by): void
    {
        if (method_exists($this, 'moveToStatus') && $this->documentStatus() === DocumentStatus::Submitted) {
            $this->moveToStatus(DocumentStatus::Draft);
        }
    }
}
