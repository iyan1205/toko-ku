<?php

namespace App\Services\Approval;

use App\Enums\ApproverType;
use App\Models\ApprovalFlow;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Simpan dan hapus alur persetujuan. Tahap disimpan ulang berurutan (1, 2, ...). Pengajuan yang
 * sedang berjalan memakai salinan tahap saat diajukan, jadi tidak terpengaruh perubahan alur.
 */
class ApprovalFlowService
{
    /** @param array<string, mixed> $data hasil ApprovalFlowRequest */
    public function save(?ApprovalFlow $flow, array $data, User $user): ApprovalFlow
    {
        return DB::transaction(function () use ($flow, $data, $user) {
            $flow ??= new ApprovalFlow;
            $flow->fill([
                'name' => trim($data['name']),
                'document_type' => $data['document_type'],
                'branch_id' => $data['branch_id'] ?? null,
                'priority' => (int) $data['priority'],
                'allow_self_approval' => (bool) ($data['allow_self_approval'] ?? false),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'description' => $data['description'] ?? null,
                'conditions' => array_map(fn (array $c) => [
                    'field' => $c['field'],
                    'operator' => $c['operator'],
                    'value' => number_format((float) $c['value'], 2, '.', ''),
                ], $data['conditions'] ?? []) ?: null,
            ])->save();

            $flow->steps()->delete();
            foreach (array_values($data['steps']) as $i => $step) {
                $type = ApproverType::from($step['approver_type']);
                $flow->steps()->create([
                    'sequence' => $i + 1,
                    'name' => trim($step['name']),
                    'approver_type' => $type,
                    'role_id' => $type === ApproverType::Role ? (int) $step['role_id'] : null,
                    'user_id' => $type === ApproverType::User ? (int) $step['user_id'] : null,
                ]);
            }

            $flow->load('steps.role', 'steps.user');

            activity('pengaturan')->performedOn($flow)->causedBy($user)->event('steps_saved')
                ->withProperties(['tahap' => $flow->steps->map(fn ($s) => "{$s->sequence}. {$s->name} ({$s->approverLabel()})")->all()])
                ->log("Tahap alur persetujuan {$flow->name} disimpan");

            return $flow;
        });
    }

    public function delete(ApprovalFlow $flow): void
    {
        $flow->delete();
    }
}
