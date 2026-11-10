<?php

namespace App\Notifications;

use App\Enums\ApprovalStatus;
use App\Models\ApprovalRequest;
use App\Models\User;

/** Ke pengaju: dokumennya disetujui (semua tahap) atau ditolak. */
class ApprovalDecided extends AppNotification
{
    public function __construct(
        public ApprovalRequest $request,
        public User $decidedBy,
        public ?string $note = null,
    ) {}

    public static function type(): string
    {
        return 'approval.decided';
    }

    public function title(): string
    {
        return $this->request->status === ApprovalStatus::Approved
            ? "Disetujui: {$this->request->title}"
            : "Ditolak: {$this->request->title}";
    }

    public function body(): string
    {
        $hasil = $this->request->status === ApprovalStatus::Approved ? 'disetujui' : 'ditolak';

        return trim(sprintf('%s %s %s oleh %s.%s',
            $this->request->documentLabel(),
            $this->request->title,
            $hasil,
            $this->decidedBy->name,
            $this->note ? " Catatan: {$this->note}" : '',
        ));
    }

    public function url(): ?string
    {
        return $this->request->approvable?->approvalUrl() ?? route('approvals.index', ['tab' => 'pengajuan']);
    }

    public function icon(): string
    {
        return $this->request->status === ApprovalStatus::Approved ? 'check-circle' : 'x-circle';
    }
}
