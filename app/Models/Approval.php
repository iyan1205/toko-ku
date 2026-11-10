<?php

namespace App\Models;

use App\Enums\ApprovalAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Riwayat persetujuan: siapa, tahap mana, aksi apa, dan catatannya. Tidak pernah diubah. */
class Approval extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['step', 'step_name', 'user_id', 'action', 'note'];

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'step' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
