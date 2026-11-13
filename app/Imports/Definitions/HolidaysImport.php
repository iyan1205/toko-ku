<?php

namespace App\Imports\Definitions;

use App\Imports\ImportDefinition;
use App\Models\Branch;
use App\Models\Holiday;
use App\Models\User;

/** Impor hari libur. Tanggal + cabang yang sudah ada → namanya diperbarui. Cabang kosong = libur nasional. */
class HolidaysImport extends ImportDefinition
{
    /** @var array<string, Holiday> "Y-m-d|branch_id" */
    private array $existing = [];

    /** @var array<string, int> */
    private array $branches = [];

    /** @var array<string, int> */
    private array $seen = [];

    public function key(): string
    {
        return 'holidays';
    }

    public function title(): string
    {
        return 'Hari libur';
    }

    public function indexRoute(): string
    {
        return 'holidays.index';
    }

    public function matchingRule(): string
    {
        return 'Baris dengan tanggal (dan cabang) yang sudah ada memperbarui nama hari libur itu. Kolom Cabang kosong = libur nasional untuk semua cabang.';
    }

    public function columns(): array
    {
        return [
            'tanggal' => ['label' => 'Tanggal', 'required' => true, 'hint' => 'Format 31/12/2026 atau 2026-12-31.', 'example' => '25/12/2026', 'text' => true],
            'nama' => ['label' => 'Nama', 'required' => true, 'hint' => 'Maksimal 100 karakter.', 'example' => 'Hari Raya Natal'],
            'cabang' => ['label' => 'Cabang', 'hint' => 'Kode cabang untuk libur khusus satu cabang. Kosongkan untuk libur nasional.', 'example' => '', 'text' => true],
        ];
    }

    public function prepare(): void
    {
        $this->existing = Holiday::query()->get()->keyBy(fn (Holiday $h) => $h->date->toDateString().'|'.($h->branch_id ?? ''))->all();
        $this->branches = Branch::query()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => $id])->all();
        $this->seen = [];
    }

    public function validate(array $row, int $line): array
    {
        $errors = [];
        $date = $this->date($row['tanggal'] ?? null);
        $name = $this->text($row['nama'] ?? null);
        $branchCode = $this->text($row['cabang'] ?? null);
        $branchId = null;

        if ($date === null) {
            $errors[] = 'Tanggal kosong atau formatnya tidak dikenali (pakai 31/12/2026 atau 2026-12-31).';
        }
        if ($name === null) {
            $errors[] = 'Nama wajib diisi.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Nama maksimal 100 karakter.';
        }
        if ($branchCode !== null) {
            $branchId = $this->branches[mb_strtolower($branchCode)] ?? null;
            if ($branchId === null) {
                $errors[] = "Cabang {$branchCode} tidak ditemukan.";
            }
        }

        if ($date !== null) {
            $key = $date->toDateString().'|'.($branchId ?? '');
            if (isset($this->seen[$key])) {
                $errors[] = "Tanggal {$date->format('d/m/Y')} muncul ganda (juga di baris {$this->seen[$key]}).";
            }
            $this->seen[$key] ??= $line;
        }

        return [['date' => $date?->toDateString(), 'name' => $name, 'branch_id' => $branchId], $errors];
    }

    public function persist(array $data, User $user): string
    {
        $key = $data['date'].'|'.($data['branch_id'] ?? '');

        if ($holiday = $this->existing[$key] ?? null) {
            $holiday->update(['name' => $data['name']]);

            return 'updated';
        }

        $this->existing[$key] = Holiday::create($data + ['is_national' => $data['branch_id'] === null]);

        return 'created';
    }
}
