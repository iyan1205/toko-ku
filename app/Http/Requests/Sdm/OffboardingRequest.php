<?php

namespace App\Http\Requests\Sdm;

use App\Enums\TerminationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OffboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.offboarding.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['deactivate_user' => $this->boolean('deactivate_user', true)]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'termination_type' => ['required', Rule::enum(TerminationType::class)],
            'notice_date' => ['required', 'date'],
            'last_working_date' => ['required', 'date', 'after_or_equal:notice_date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'deactivate_user' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['termination_type' => 'alasan berhenti', 'notice_date' => 'tanggal pemberitahuan', 'last_working_date' => 'hari terakhir kerja', 'reason' => 'keterangan'];
    }
}
