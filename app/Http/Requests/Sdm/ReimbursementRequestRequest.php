<?php

namespace App\Http\Requests\Sdm;

use App\Services\Documents\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReimbursementRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.reimbursements.create');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('amount')) {
            $this->merge(['amount' => str_replace(['.', ' '], '', (string) $this->input('amount'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expense_date' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.now()->subDays(90)->toDateString()],
            'category' => ['required', Rule::in(array_keys(config('sdm.reimbursement_categories')))],
            'amount' => ['required', 'numeric', 'min:1000', 'max:100000000'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'receipts' => ['required', 'array', 'min:1', 'max:5'],
            'receipts.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:'.app(AttachmentService::class)->maxKilobytes()],
        ];
    }

    public function attributes(): array
    {
        return ['expense_date' => 'tanggal pengeluaran', 'category' => 'kategori', 'amount' => 'jumlah', 'reason' => 'keterangan', 'receipts' => 'bukti', 'receipts.*' => 'bukti'];
    }

    public function messages(): array
    {
        return [
            'expense_date.after_or_equal' => 'Pengeluaran lebih dari 90 hari lalu tidak bisa diajukan.',
            'receipts.required' => 'Lampirkan foto struk atau bukti pembayaran.',
        ];
    }
}
