<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Alur persetujuan untuk satu jenis dokumen (config/approval.php). Alur berlaku bila semua
 * kondisinya terpenuhi (tanpa kondisi = semua dokumen jenis itu) dan cabangnya cocok
 * (null = semua cabang). Bila beberapa alur cocok, dipakai prioritas terkecil.
 *
 * @property list<array{field: string, operator: string, value: string}>|null $conditions
 */
class ApprovalFlow extends Model
{
    use RecordsActivity, SoftDeletes;

    protected $fillable = ['name', 'document_type', 'branch_id', 'conditions', 'priority', 'allow_self_approval', 'is_active', 'description'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'priority' => 'integer',
            'allow_self_approval' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('sequence');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function requests(): HasMany
    {
        return $this->hasMany(ApprovalRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function documentLabel(): string
    {
        return config("approval.documents.{$this->document_type}.label", $this->document_type);
    }

    /** Semua kondisi terpenuhi oleh nilai dokumen. Nilai uang dibandingkan dalam sen. */
    public function matches(callable $value): bool
    {
        foreach ($this->conditions ?? [] as $condition) {
            $actual = Uang::sen($value($condition['field']) ?? 0);
            $target = Uang::sen($condition['value'] ?? 0);

            $ok = match ($condition['operator'] ?? '') {
                '>' => $actual > $target,
                '>=' => $actual >= $target,
                '<' => $actual < $target,
                '<=' => $actual <= $target,
                '=' => $actual === $target,
                default => false,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /** "Total lebih dari Rp 5.000.000", atau "Semua dokumen" tanpa kondisi. */
    public function conditionSummary(): string
    {
        $fields = config("approval.documents.{$this->document_type}.fields", []);
        $operators = config('approval.operators', []);

        $parts = array_map(fn (array $c) => trim(sprintf('%s %s %s',
            $fields[$c['field']] ?? $c['field'],
            $operators[$c['operator']] ?? $c['operator'],
            Format::rupiah($c['value']),
        )), $this->conditions ?? []);

        return $parts === [] ? 'Semua dokumen' : implode(' dan ', $parts);
    }

    protected function activityLogName(): string
    {
        return 'pengaturan';
    }

    protected function activityLogLabel(): string
    {
        return 'Alur persetujuan';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['name', 'document_type', 'branch_id', 'conditions', 'priority', 'allow_self_approval', 'is_active'];
    }
}
