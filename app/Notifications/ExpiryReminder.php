<?php

namespace App\Notifications;

/** Ringkasan harian batch yang sudah/akan kedaluwarsa (perintah stok:kedaluwarsa). */
class ExpiryReminder extends AppNotification
{
    /** @param array{lots: int, expired: int, days: int} $summary */
    public function __construct(public array $summary) {}

    public static function type(): string
    {
        return 'inventory.expiry';
    }

    public function title(): string
    {
        return 'Barang mendekati kedaluwarsa';
    }

    public function body(): string
    {
        $s = $this->summary;

        return "{$s['lots']} batch kedaluwarsa dalam {$s['days']} hari".($s['expired'] > 0 ? ", {$s['expired']} di antaranya sudah lewat" : '').'.';
    }

    public function url(): ?string
    {
        return route('stock.expiring');
    }

    public function icon(): string
    {
        return 'clock';
    }
}
