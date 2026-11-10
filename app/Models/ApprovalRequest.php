<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Satu pengajuan persetujuan dokumen. Tahap disalin dari alur saat diajukan (steps), sehingga
 * perubahan alur tidak mengganggu pengajuan yang berjalan. current_* = penyetuju tahap yang
 * sedang menunggu (dipakai kotak masuk "Persetujuan Saya"). Hanya diubah lewat ApprovalService.
 *
 * @property list<array{sequence: int, name: string, approver_type: string, role_id: ?int, role_name: ?string, user_id: ?int, user_name: ?string}> $steps
 */
class ApprovalRequest extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'steps' => 'array',
            'amount' => 'decimal:2',
            'allow_self_approval' => 'boolean',
            'current_step' => 'integer',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function history(): HasMany
    {
        return $this->hasMany(Approval::class)->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === ApprovalStatus::Pending;
    }

    /** @return array{sequence: int, name: string, approver_type: string, role_id: ?int, role_name: ?string, user_id: ?int, user_name: ?string}|null */
    public function currentStep(): ?array
    {
        return collect($this->steps)->firstWhere('sequence', $this->current_step);
    }

    public function stepCount(): int
    {
        return count($this->steps);
    }

    /** "Tahap 1 dari 2 · Kepala Gudang (Peran Admin)". */
    public function progressLabel(): string
    {
        $step = $this->currentStep();

        if (! $this->isPending() || $step === null) {
            return $this->status->label();
        }

        return sprintf('Tahap %d dari %d · %s (%s)', $step['sequence'], $this->stepCount(), $step['name'], self::approverLabel($step));
    }

    /** @param array{approver_type: string, role_name: ?string, user_name: ?string} $step */
    public static function approverLabel(array $step): string
    {
        return $step['approver_type'] === 'role' ? 'Peran '.($step['role_name'] ?? '-') : ($step['user_name'] ?? '-');
    }

    public function documentLabel(): string
    {
        return config("approval.documents.{$this->document_type}.label", $this->document_type);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Pending->value);
    }
}
