<?php

namespace App\Http\Requests\Accounting;

use App\Models\RecurringJournal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Templat jurnal berulang. Aturan baris (akun, seimbang) diperiksa JournalService::validateLines(). */
class RecurringJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('recurringJournal') ? 'accounting.recurring.update' : 'accounting.recurring.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'auto_post' => $this->boolean('auto_post'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uang = ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'];

        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:200'],
            'interval_months' => ['required', 'integer', Rule::in(array_keys(RecurringJournal::INTERVALS))],
            'day_of_month' => ['required', 'integer', 'between:1,31'],
            'start_on' => ['required', 'date'],
            'end_on' => ['nullable', 'date', 'after_or_equal:start_on'],
            'auto_post' => ['boolean'],
            'is_active' => ['boolean'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.cost_center_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => $uang,
            'lines.*.credit' => $uang,
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama templat',
            'description' => 'keterangan jurnal',
            'interval_months' => 'frekuensi',
            'day_of_month' => 'tanggal',
            'start_on' => 'mulai',
            'end_on' => 'sampai',
            'lines' => 'baris jurnal',
            'lines.*.debit' => 'debit',
            'lines.*.credit' => 'kredit',
        ];
    }
}
