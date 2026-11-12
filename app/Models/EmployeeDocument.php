<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\TracksAuthors;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Dokumen karyawan (KTP, ijazah, SIM, sertifikat, ...) dengan masa berlaku; berkasnya lampiran. */
class EmployeeDocument extends Model
{
    use HasAttachments, SoftDeletes, TracksAuthors;

    protected $fillable = ['type', 'name', 'number', 'issued_at', 'expires_at', 'notes'];

    protected function casts(): array
    {
        return ['issued_at' => 'date', 'expires_at' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function typeLabel(): string
    {
        return config("sdm.document_types.{$this->type}", $this->type);
    }

    /** Sisa hari sampai kedaluwarsa (negatif = sudah lewat), null bila tanpa masa berlaku. */
    public function daysLeft(): ?int
    {
        return $this->expires_at ? (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($this->expires_at), false) : null;
    }
}
