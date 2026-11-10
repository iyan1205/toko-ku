<?php

namespace App\Services\Import;

use App\Exceptions\AturanBisnisException;
use App\Imports\ImportDefinition;
use App\Imports\RowsReader;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Impor massal dua langkah:
 *   1. preview(): baca berkas, periksa judul kolom wajib, validasi setiap baris, simpan hasilnya
 *      di import_batches. Belum ada data yang berubah.
 *   2. commit(): validasi ulang (data bisa berubah sejak pratinjau), simpan baris yang valid dalam
 *      satu transaksi, baris yang salah dilewati. Hasilnya tercatat di log aktivitas.
 */
class ImportService
{
    public function definition(string $type): ImportDefinition
    {
        $class = config("imports.types.{$type}.class");
        abort_if($class === null, 404);

        return app($class);
    }

    public function preview(ImportDefinition $definition, UploadedFile $file, User $user): ImportBatch
    {
        $reader = new RowsReader;
        Excel::import($reader, $file);

        $columns = array_keys($definition->columns());
        $rows = [];
        foreach ($reader->rows->values() as $i => $row) {
            $raw = array_map(fn ($v) => is_string($v) ? trim($v) : $v, array_intersect_key($row->all(), array_flip($columns)));
            if (array_filter($raw, fn ($v) => $v !== null && $v !== '') !== []) {
                $rows[] = ['line' => $i + 2, 'raw' => $raw];
            }
        }

        if ($rows === []) {
            throw new AturanBisnisException('Berkas tidak berisi data. Isi baris di bawah judul kolom templat.');
        }
        if (count($rows) > $definition->maxRows()) {
            throw new AturanBisnisException("Maksimal {$definition->maxRows()} baris per impor; berkas berisi ".count($rows).' baris.');
        }

        $headings = array_keys($reader->rows->first()?->all() ?? []);
        $missing = array_diff($definition->requiredColumns(), $headings);
        if ($missing !== []) {
            $labels = array_map(fn (string $key) => $definition->columns()[$key]['label'], $missing);
            throw new AturanBisnisException('Kolom wajib tidak ditemukan di baris judul: '.implode(', ', $labels).'. Pakai templat dari tombol Unduh templat.');
        }

        $definition->prepare();
        $checked = array_map(function (array $row) use ($definition) {
            [, $errors] = $definition->validate($row['raw'], $row['line']);

            return $row + ['errors' => $errors];
        }, $rows);

        $invalid = count(array_filter($checked, fn (array $r) => $r['errors'] !== []));

        $batch = new ImportBatch;
        $batch->forceFill([
            'type' => $definition->key(),
            'user_id' => $user->getKey(),
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => ImportBatch::STATUS_PREVIEWED,
            'total_rows' => count($checked),
            'valid_rows' => count($checked) - $invalid,
            'invalid_rows' => $invalid,
            'rows' => $checked,
        ])->save();

        return $batch;
    }

    public function commit(ImportDefinition $definition, ImportBatch $batch, User $user): ImportBatch
    {
        return DB::transaction(function () use ($definition, $batch, $user) {
            $locked = ImportBatch::query()->whereKey($batch->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isPreviewed()) {
                throw new AturanBisnisException('Impor ini sudah diproses.');
            }

            $definition->prepare();
            $counts = ['created' => 0, 'updated' => 0];
            $rows = [];

            foreach ($locked->rows as $row) {
                [$data, $errors] = $definition->validate($row['raw'], $row['line']);

                if ($errors === []) {
                    $counts[$definition->persist($data, $user)]++;
                }

                $rows[] = ['line' => $row['line'], 'raw' => $row['raw'], 'errors' => $errors];
            }

            $invalid = count(array_filter($rows, fn (array $r) => $r['errors'] !== []));

            $locked->forceFill([
                'status' => ImportBatch::STATUS_COMPLETED,
                'rows' => $rows,
                'valid_rows' => count($rows) - $invalid,
                'invalid_rows' => $invalid,
                'created_count' => $counts['created'],
                'updated_count' => $counts['updated'],
                'completed_at' => now(),
            ])->save();

            activity('impor')->performedOn($locked)->causedBy($user)->event('imported')
                ->withProperties(['jenis' => $definition->title(), 'berkas' => $locked->file_name, 'dibuat' => $counts['created'], 'diperbarui' => $counts['updated'], 'dilewati' => $invalid])
                ->log("Impor {$definition->title()}: {$counts['created']} ditambahkan, {$counts['updated']} diperbarui, {$invalid} dilewati");

            return $locked;
        });
    }
}
