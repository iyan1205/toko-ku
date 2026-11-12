<?php

namespace App\Notifications;

/**
 * Ringkasan harian kontrak kerja dan dokumen karyawan yang akan/sudah berakhir
 * (perintah sdm:pengingat). Satu notifikasi per pengguna.
 */
class HrExpiryReminder extends AppNotification
{
    /** @param array{contracts: int, contracts_late: int, documents: int, documents_late: int, days: int} $summary */
    public function __construct(public array $summary) {}

    public static function type(): string
    {
        return 'sdm.expiry';
    }

    public function title(): string
    {
        return 'Kontrak & dokumen karyawan';
    }

    public function body(): string
    {
        $s = $this->summary;
        $parts = [];
        if ($s['contracts'] > 0) {
            $parts[] = "{$s['contracts']} kontrak berakhir dalam {$s['days']} hari".($s['contracts_late'] > 0 ? " ({$s['contracts_late']} sudah lewat)" : '');
        }
        if ($s['documents'] > 0) {
            $parts[] = "{$s['documents']} dokumen kedaluwarsa dalam {$s['days']} hari".($s['documents_late'] > 0 ? " ({$s['documents_late']} sudah lewat)" : '');
        }

        return implode(', ', $parts).'.';
    }

    public function url(): ?string
    {
        return route('employees.index', ['status' => 'aktif']);
    }

    public function icon(): string
    {
        return 'identification';
    }
}
