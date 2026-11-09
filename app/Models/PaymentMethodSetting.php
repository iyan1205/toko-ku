<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan satu metode pembayaran di kasir (nama tampilan, aktif, urutan).
 * Dibaca lewat PaymentMethodService, bukan langsung.
 */
class PaymentMethodSetting extends Model
{
    protected $table = 'payment_methods';

    protected $fillable = ['code', 'label', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'code' => PaymentMethod::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
