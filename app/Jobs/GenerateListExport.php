<?php

namespace App\Jobs;

use App\Exports\ListExport;
use App\Models\ExportFile;
use App\Notifications\ExportReady;
use App\Support\CurrentBranch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Buat berkas ekspor (Excel/PDF) dari definisi ListExport yang sama dengan halaman daftarnya,
 * dengan filter dan saringan cabang pemintanya, lalu beri tahu pemintanya lewat notifikasi.
 */
class GenerateListExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public ExportFile $export) {}

    public function handle(CurrentBranch $branches): void
    {
        $this->export->forceFill(['status' => ExportFile::PROCESSING])->save();
        $branches->restrictTo($this->export->branch_ids);

        try {
            /** @var ListExport $list */
            $list = new ($this->export->export_class)($this->export->filters ?? []);
            $path = 'exports/'.$this->export->user_id.'/'.$this->export->getKey().'-'.$this->export->file_name;

            if ($this->export->format === 'pdf') {
                Storage::disk($this->export->disk)->put($path, $list->renderPdf()->output());
            } else {
                Excel::store($list, $path, $this->export->disk);
            }

            $this->export->forceFill([
                'status' => ExportFile::COMPLETED,
                'path' => $path,
                'row_count' => $list->query()->count(),
                'completed_at' => now(),
                'expires_at' => now()->addDays(ExportFile::KEEP_DAYS),
            ])->save();
        } finally {
            $branches->forgetRestriction();
        }

        $this->export->user?->notify(new ExportReady($this->export));
    }

    public function failed(?Throwable $e): void
    {
        $this->export->forceFill([
            'status' => ExportFile::FAILED,
            'error' => mb_substr((string) $e?->getMessage(), 0, 1000),
        ])->save();

        $this->export->user?->notify(new ExportReady($this->export));
    }
}
