<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\TracksAuthors;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Aturan upah lembur dengan tanggal berlaku (Pengaturan → SDM). Tingkat (tier) = daftar
 * {hours, multiplier}: jam ke-1..n dikali pengali; hours null = sisa jam berikutnya.
 */
class OvertimeRule extends Model
{
    use RecordsActivity, TracksAuthors;

    public const MODES = ['multiplier' => 'Pengali upah sejam (PP 35/2021)', 'flat' => 'Tarif tetap per jam'];

    protected $fillable = ['effective_from', 'mode', 'divisor', 'workday_tiers', 'rest_day_tiers_6', 'rest_day_tiers_5', 'flat_rate', 'rounding_minutes', 'min_minutes', 'notes'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date', 'divisor' => 'integer', 'workday_tiers' => 'array', 'rest_day_tiers_6' => 'array',
            'rest_day_tiers_5' => 'array', 'flat_rate' => 'decimal:2', 'rounding_minutes' => 'integer', 'min_minutes' => 'integer',
        ];
    }

    /** Aturan yang berlaku pada tanggal itu, atau null bila belum ada. */
    public static function for(CarbonInterface $date): ?self
    {
        return static::query()->whereDate('effective_from', '<=', $date->toDateString())->orderByDesc('effective_from')->first();
    }

    /**
     * Tingkat untuk jenis hari ini.
     *
     * @return list<array{hours: ?int, multiplier: string}>
     */
    public function tiers(string $dayType, int $workweek): array
    {
        return match (true) {
            $dayType === 'work' => $this->workday_tiers,
            $workweek === 5 => $this->rest_day_tiers_5,
            default => $this->rest_day_tiers_6,
        } ?? [];
    }

    /**
     * Isian teks "1:1,5; *:2" (jam:pengali, dipisah titik koma; * = sisa jam) → daftar tingkat, atau null bila salah.
     *
     * @return list<array{hours: ?int, multiplier: string}>|null
     */
    public static function parseTiers(?string $text): ?array
    {
        $tiers = [];
        $parts = array_values(array_filter(array_map('trim', explode(';', (string) $text)), fn ($p) => $p !== ''));
        foreach ($parts as $i => $part) {
            if (! preg_match('/^(\d{1,2}|\*)\s*[:x×]\s*(\d{1,2}(?:[.,]\d{1,2})?)$/u', $part, $m)) {
                return null;
            }
            if ($m[1] === '*' && $i !== count($parts) - 1) {
                return null; // "sisa" hanya boleh di akhir
            }
            $tiers[] = ['hours' => $m[1] === '*' ? null : (int) $m[1], 'multiplier' => str_replace(',', '.', $m[2])];
        }

        return $tiers === [] ? null : $tiers;
    }

    /** Daftar tingkat → isian teks "1:1,5; *:2". */
    public static function tiersInput(?array $tiers): string
    {
        return collect($tiers ?? [])->map(fn (array $t) => ($t['hours'] ?? '*').':'.str_replace('.', ',', (string) $t['multiplier']))->join('; ');
    }

    /** "1 jam × 1,5 · sisa × 2" */
    public static function tiersLabel(?array $tiers): string
    {
        return collect($tiers ?? [])->map(fn (array $t) => ($t['hours'] === null ? 'sisa' : $t['hours'].' jam').' × '.str_replace('.', ',', rtrim(rtrim((string) $t['multiplier'], '0'), '.')))->join(' · ');
    }

    protected function activityLogName(): string
    {
        return 'pengaturan';
    }

    protected function activityLogLabel(): string
    {
        return 'Aturan upah lembur';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['effective_from', 'mode', 'divisor', 'workday_tiers', 'rest_day_tiers_6', 'rest_day_tiers_5', 'flat_rate', 'rounding_minutes', 'min_minutes'];
    }
}
