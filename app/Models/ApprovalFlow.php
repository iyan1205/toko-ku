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

    /**
     * Format nilai kondisi satu kolom: money (bawaan, Rupiah), number (+ unit), option (+ daftar pilihan).
     *
     * @return array{type: string, unit: ?string, options: array<string, string>}
     */
    public static function fieldFormat(string $documentType, string $field): array
    {
        $format = config("approval.documents.{$documentType}.formats.{$field}", []);

        return [
            'type' => $format['type'] ?? 'money',
            'unit' => $format['unit'] ?? null,
            'options' => isset($format['options']) ? self::fieldOptions($format['options']) : [],
        ];
    }

    /** Daftar pilihan untuk kolom bertipe option (id => nama). @return array<string, string> */
    public static function fieldOptions(string $source): array
    {
        return match ($source) {
            'leave_types' => JenisCuti::query()->orderBy('nama')->pluck('nama', 'id')->mapWithKeys(fn ($nama, $id) => [(string) $id => $nama])->all(),
            default => [],
        };
    }

    /** Nilai kondisi untuk dibaca orang: "Rp 5.000.000", "3 jam", "Cuti Tahunan". */
    public function conditionValueLabel(string $field, mixed $value): string
    {
        $format = self::fieldFormat($this->document_type, $field);

        return match ($format['type']) {
            'number' => trim(rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').' '.$format['unit']),
            'option' => $format['options'][(string) (int) $value] ?? (string) $value,
            default => Format::rupiah($value),
        };
    }

    /** "Total lebih dari Rp 5.000.000", atau "Semua dokumen" tanpa kondisi. */
    public function conditionSummary(): string
    {
        $fields = config("approval.documents.{$this->document_type}.fields", []);
        $operators = config('approval.operators', []);

        $parts = array_map(fn (array $c) => trim(sprintf('%s %s %s',
            $fields[$c['field']] ?? $c['field'],
            $operators[$c['operator']] ?? $c['operator'],
            $this->conditionValueLabel($c['field'], $c['value']),
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
