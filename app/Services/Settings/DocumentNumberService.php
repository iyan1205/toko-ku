<?php

namespace App\Services\Settings;

use App\Enums\DocumentType;
use App\Enums\NumberingReset;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen (penjualan, pembelian, pembayaran, opname, cuti) dari pola di
 * Pengaturan → Penomoran Dokumen, misal INV/{YYYY}/{MM}/{####} → INV/2026/11/0001.
 *
 * Penanda: {YYYY} tahun 4 digit, {YY} tahun 2 digit, {MM} bulan, {DD} tanggal,
 * {###} s.d. {########} nomor urut (jumlah # = jumlah digit minimal).
 *
 * Nomor urut disimpan per jenis dokumen dan periode reset di tabel document_sequences.
 * Pola baru hanya berlaku untuk dokumen baru; nomor dokumen lama tidak diubah.
 */
class DocumentNumberService
{
    private const TOKEN = '/\{(YYYY|YY|MM|DD|#{3,8})\}/';

    private const COUNTER = '/\{#{3,8}\}/';

    private const LITERAL = '/^[A-Za-z0-9\/\-._]*$/';

    /** @return array{pattern: string, reset: NumberingReset} */
    public function format(DocumentType $type): array
    {
        return [
            'pattern' => (string) setting("numbering.{$type->value}.pattern", $type->defaultPattern()),
            'reset' => NumberingReset::tryFrom((string) setting("numbering.{$type->value}.reset")) ?? $type->defaultReset(),
        ];
    }

    /**
     * Nomor berikutnya untuk dokumen bertanggal $date.
     *
     * Wajib dipanggil di dalam DB::transaction() yang juga menyimpan dokumennya: baris urutan
     * terkunci sampai transaksi selesai, dan bila transaksi batal nomornya ikut batal
     * (tidak ada nomor yang terlewat).
     */
    public function next(DocumentType $type, DateTimeInterface|string $date): string
    {
        $date = CarbonImmutable::parse($date);
        ['pattern' => $pattern, 'reset' => $reset] = $this->format($type);
        $period = $reset->periodKey($date);

        DB::table('document_sequences')->insertOrIgnore([
            'type' => $type->value,
            'period' => $period,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('document_sequences')
            ->where('type', $type->value)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        // Lewati nomor yang sudah dipakai, misal setelah pola diganti kembali ke pola lama
        // atau dua jenis dokumen yang berbagi tabel diberi pola yang sama.
        $counter = (int) $sequence->last_number;
        do {
            $counter++;
            $number = $this->render($pattern, $date, $counter);
        } while ($this->numberTaken($type, $number));

        DB::table('document_sequences')->where('id', $sequence->id)
            ->update(['last_number' => $counter, 'updated_at' => now()]);

        return $number;
    }

    /** Isi pola untuk tanggal dan nomor urut tertentu. */
    public function render(string $pattern, DateTimeInterface $date, int $counter): string
    {
        return preg_replace_callback(self::TOKEN, fn (array $m) => match ($m[1]) {
            'YYYY' => $date->format('Y'),
            'YY' => $date->format('y'),
            'MM' => $date->format('m'),
            'DD' => $date->format('d'),
            default => str_pad((string) $counter, strlen($m[1]), '0', STR_PAD_LEFT),
        }, $pattern);
    }

    /** Contoh nomor pertama hari ini, untuk pratinjau. */
    public function example(DocumentType $type, ?string $pattern = null): string
    {
        return $this->render($pattern ?? $this->format($type)['pattern'], CarbonImmutable::now(), 1);
    }

    /** Pesan kesalahan bila pola tidak bisa dipakai, atau null bila sah. */
    public function patternError(DocumentType $type, string $pattern, NumberingReset $reset): ?string
    {
        if (preg_match_all(self::COUNTER, $pattern) !== 1) {
            return 'Pola harus memuat tepat satu nomor urut, misal {####}.';
        }

        $literal = preg_replace(self::TOKEN, '', $pattern);
        if (str_contains($literal, '{') || str_contains($literal, '}')) {
            return 'Penanda tidak dikenal. Gunakan {YYYY}, {YY}, {MM}, {DD}, dan {####}.';
        }
        if (! preg_match(self::LITERAL, $literal)) {
            return 'Selain penanda, pola hanya boleh berisi huruf, angka, dan tanda / - . _';
        }

        foreach ($reset->requiredTokens() as $alternatives) {
            if (! array_filter($alternatives, fn (string $token) => str_contains($pattern, $token))) {
                return sprintf('Nomor direset %s, jadi pola harus memuat %s agar nomor tidak berulang.',
                    mb_strtolower($reset->label()), implode(' atau ', $alternatives));
            }
        }

        if (mb_strlen($this->render($pattern, CarbonImmutable::now(), 1)) > $type->maxLength()) {
            return "Nomor yang dihasilkan terlalu panjang (maksimal {$type->maxLength()} karakter).";
        }

        return null;
    }

    /**
     * Simpan pola semua dokumen. Hanya yang berubah yang dicatat di log aktivitas.
     *
     * @param  array<string, array{pattern: string, reset: string}>  $formats  kunci = DocumentType::value
     */
    public function save(array $formats, User $actor): void
    {
        $before = $this->snapshot();

        $pairs = [];
        foreach (DocumentType::cases() as $type) {
            if (isset($formats[$type->value])) {
                $pairs["numbering.{$type->value}.pattern"] = trim($formats[$type->value]['pattern']);
                $pairs["numbering.{$type->value}.reset"] = $formats[$type->value]['reset'];
            }
        }
        setting($pairs);

        $after = $this->snapshot();
        $changed = array_keys(array_diff_assoc($after, $before));

        if ($changed !== []) {
            activity('pengaturan')->causedBy($actor)->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log('Penomoran dokumen diubah');
        }
    }

    /** @return array<string, string> "Penjualan kasir" => "POS-{YYYY}{MM}{DD}-{####} (Setiap hari)" */
    private function snapshot(): array
    {
        $result = [];
        foreach (DocumentType::cases() as $type) {
            $format = $this->format($type);
            $result[$type->label()] = "{$format['pattern']} ({$format['reset']->label()})";
        }

        return $result;
    }

    private function numberTaken(DocumentType $type, string $number): bool
    {
        $model = $type->model();
        $query = in_array(SoftDeletes::class, class_uses_recursive($model), true)
            ? $model::withTrashed()
            : $model::query();

        return $query->where($type->column(), $number)->exists();
    }
}
