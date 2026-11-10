<?php

namespace App\Notifications;

use App\Models\ApprovalRequest;
use App\Support\Format;

/** Ke penyetuju tahap yang sedang menunggu: ada dokumen yang perlu disetujui. */
class ApprovalRequested extends AppNotification
{
    public function __construct(public ApprovalRequest $request) {}

    public static function type(): string
    {
        return 'approval.requested';
    }

    public function title(): string
    {
        return "Menunggu persetujuan: {$this->request->title}";
    }

    public function body(): string
    {
        $step = $this->request->currentStep();

        return sprintf(
            '%s %s diajukan %s%s. Tahap %d dari %d: %s.',
            $this->request->documentLabel(),
            $this->request->title,
            $this->request->submitter?->name ?? '-',
            $this->request->amount !== null ? ' senilai '.Format::rupiah($this->request->amount) : '',
            $step['sequence'] ?? 1,
            $this->request->stepCount(),
            $step['name'] ?? '-',
        );
    }

    public function url(): ?string
    {
        return route('approvals.index');
    }

    public function icon(): string
    {
        return 'clipboard-document-check';
    }
}
