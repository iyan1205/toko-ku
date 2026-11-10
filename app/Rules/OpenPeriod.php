<?php

namespace App\Rules;

use App\Services\Accounting\FiscalPeriodService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * Tanggal transaksi tidak boleh di periode yang ditutup (Akuntansi → Kunci Periode).
 * Pakai bersama 'date': ['required', 'date', new OpenPeriod].
 */
class OpenPeriod implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $date = CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return; // format tanggal dicek aturan 'date'
        }

        $periods = app(FiscalPeriodService::class);

        if ($periods->isClosed($date)) {
            $fail($periods->message($date));
        }
    }
}
