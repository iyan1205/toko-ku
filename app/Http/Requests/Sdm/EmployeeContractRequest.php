<?php

namespace App\Http\Requests\Sdm;

use App\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.employees.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'number' => ['nullable', 'string', 'max:50'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', Rule::requiredIf($this->input('employment_type') === EmploymentType::Contract->value)],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['number' => 'nomor kontrak', 'employment_type' => 'status kerja', 'start_date' => 'tanggal mulai', 'end_date' => 'tanggal berakhir', 'notes' => 'catatan'];
    }

    public function messages(): array
    {
        return ['end_date.required' => 'Kontrak PKWT wajib punya tanggal berakhir.'];
    }
}
