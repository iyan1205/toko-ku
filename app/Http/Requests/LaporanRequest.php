<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filter rentang tanggal laporan. Bila tidak diisi, default-nya bulan berjalan
 * (tanggal 1 sampai hari ini) supaya halaman langsung menampilkan angka yang berguna.
 */
class LaporanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.sales.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ];
    }

    public function dari(): CarbonImmutable
    {
        return $this->filled('dari')
            ? CarbonImmutable::parse($this->input('dari'))->startOfDay()
            : CarbonImmutable::today()->startOfMonth();
    }

    public function sampai(): CarbonImmutable
    {
        return $this->filled('sampai')
            ? CarbonImmutable::parse($this->input('sampai'))->endOfDay()
            : CarbonImmutable::today()->endOfDay();
    }
}
