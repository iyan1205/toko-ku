<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/** Tutup sesi kasir: uang fisik yang dihitung di laci. Hak menutup dicek CashSessionPolicy. */
class CloseCashSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'closing_cash' => ['required', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],
            'closing_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['closing_cash' => 'uang fisik', 'closing_note' => 'catatan'];
    }
}
