<?php

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/** Bentuk data jurnal manual. Aturan debit = kredit dan akun diperiksa JournalService. */
class JournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('accounting.journals.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uang = ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'];

        return [
            'journal_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => $uang,
            'lines.*.credit' => $uang,
        ];
    }

    public function attributes(): array
    {
        return [
            'journal_date' => 'tanggal',
            'description' => 'keterangan',
            'lines' => 'baris jurnal',
            'lines.*.debit' => 'debit',
            'lines.*.credit' => 'kredit',
        ];
    }

    public function messages(): array
    {
        return ['journal_date.before_or_equal' => 'Tanggal jurnal tidak boleh lebih dari hari ini.'];
    }
}
