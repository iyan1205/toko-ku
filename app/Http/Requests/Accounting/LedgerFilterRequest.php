<?php

namespace App\Http\Requests\Accounting;

use App\Enums\AccountType;
use App\Services\Accounting\LedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saringan Buku Besar dan Neraca Saldo (GET). Tanpa tanggal: awal bulan ini s.d. hari ini.
 * Izin halaman dijaga middleware route.
 */
class LedgerFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'akun' => ['nullable', 'integer', 'exists:accounts,id'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
            'cabang' => ['nullable', 'integer', Rule::in(app(LedgerService::class)->branchOptions()->pluck('id')->all())],
            'cost_center' => ['nullable', 'regex:/^(tanpa|\d{1,10})$/'],
            'tipe' => ['nullable', Rule::enum(AccountType::class)],
            'nol' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['akun' => 'akun', 'dari' => 'tanggal awal', 'sampai' => 'tanggal akhir', 'cabang' => 'cabang', 'cost_center' => 'cost center'];
    }

    /** @return array<string, mixed> saringan bersih dengan tanggal bawaan */
    public function filters(): array
    {
        $data = $this->validated();
        $data['dari'] = ! empty($data['dari']) ? date('Y-m-d', strtotime($data['dari'])) : now()->startOfMonth()->toDateString();
        $data['sampai'] = ! empty($data['sampai']) ? date('Y-m-d', strtotime($data['sampai'])) : now()->toDateString();
        $data['nol'] = (bool) ($data['nol'] ?? false);

        return $data;
    }
}
