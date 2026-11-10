<?php

namespace App\Notifications;

use App\Models\ExportFile;
use App\Support\Format;

/** Ke peminta ekspor besar: berkas siap diunduh, atau gagal dibuat. */
class ExportReady extends AppNotification
{
    public function __construct(public ExportFile $export) {}

    public static function type(): string
    {
        return 'export.ready';
    }

    public function title(): string
    {
        return $this->export->status === ExportFile::COMPLETED
            ? "Ekspor siap: {$this->export->title}"
            : "Ekspor gagal: {$this->export->title}";
    }

    public function body(): string
    {
        return $this->export->status === ExportFile::COMPLETED
            ? sprintf('%s (%s baris, %s) bisa diunduh sampai %s.', $this->export->file_name, Format::jumlah($this->export->row_count), strtoupper($this->export->format), Format::tanggal($this->export->expires_at))
            : 'Berkas tidak berhasil dibuat. Coba ekspor lagi, atau hubungi admin bila terus gagal.';
    }

    public function url(): ?string
    {
        return route('exports.index');
    }

    public function icon(): string
    {
        return $this->export->status === ExportFile::COMPLETED ? 'document-arrow-down' : 'exclamation-triangle';
    }
}
