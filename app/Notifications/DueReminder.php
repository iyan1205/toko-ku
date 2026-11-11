<?php

namespace App\Notifications;

use App\Support\Format;
use App\Support\Uang;

/**
 * Ringkasan harian piutang/hutang yang jatuh tempo dalam beberapa hari dan yang sudah lewat
 * (perintah keuangan:pengingat-jatuh-tempo). Satu notifikasi per pengguna per sisi.
 */
class DueReminder extends AppNotification
{
    /**
     * @param  array{soon_count: int, soon_total: int, late_count: int, late_total: int, days: int}  $summary  nilai dalam sen
     */
    public function __construct(public bool $receivable, public array $summary) {}

    public static function type(): string
    {
        return 'finance.due';
    }

    public function title(): string
    {
        return $this->receivable ? 'Piutang jatuh tempo' : 'Hutang jatuh tempo';
    }

    public function body(): string
    {
        $parts = [];
        if ($this->summary['late_count'] > 0) {
            $parts[] = "{$this->summary['late_count']} lewat jatuh tempo (".Format::rupiah(Uang::keDesimal($this->summary['late_total'])).')';
        }
        if ($this->summary['soon_count'] > 0) {
            $parts[] = "{$this->summary['soon_count']} jatuh tempo dalam {$this->summary['days']} hari (".Format::rupiah(Uang::keDesimal($this->summary['soon_total'])).')';
        }

        return ($this->receivable ? 'Tagihan ke pelanggan: ' : 'Tagihan dari supplier: ').implode(', ', $parts).'.';
    }

    public function url(): ?string
    {
        return $this->receivable ? route('receivable-reports.aging', ['dasar' => 'jatuh_tempo']) : route('payable-reports.schedule');
    }

    public function icon(): string
    {
        return 'calendar-days';
    }
}
