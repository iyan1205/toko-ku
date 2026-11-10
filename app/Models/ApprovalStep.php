<?php

namespace App\Models;

use App\Enums\ApproverType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

/** Satu tahap alur persetujuan. Penyetuju: semua pengguna aktif berperan role_id, atau user_id. */
class ApprovalStep extends Model
{
    protected $fillable = ['sequence', 'name', 'approver_type', 'role_id', 'user_id'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'approver_type' => ApproverType::class,
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ApprovalFlow::class, 'approval_flow_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** "Peran Admin" / "Budi Santoso", atau null bila peran/pengguna sudah dihapus. */
    public function approverLabel(): ?string
    {
        return $this->approver_type === ApproverType::Role
            ? ($this->role ? "Peran {$this->role->name}" : null)
            : ($this->user && ! $this->user->trashed() ? $this->user->name : null);
    }
}
