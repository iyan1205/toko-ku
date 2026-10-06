<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Catatan uang bersifat tambah-saja. Satu-satunya perubahan yang diizinkan adalah menandai
 * pembayaran sebagai dibatalkan (voided_*) bersama pembatalan dokumennya; tidak ada yang dihapus.
 */
class Payment extends Model
{
    private const KOLOM_VOID = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = ['number', 'payable_type', 'payable_id', 'amount', 'method', 'paid_at', 'note', 'user_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Payment $pembayaran) {
            $terlarang = array_diff(array_keys($pembayaran->getDirty()), self::KOLOM_VOID);

            if ($terlarang) {
                throw new LogicException('Catatan pembayaran tidak boleh diubah.');
            }
        });

        static::deleting(fn () => throw new LogicException('Catatan pembayaran tidak boleh dihapus.'));
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function dibatalkan(): bool
    {
        return $this->voided_at !== null;
    }
}
