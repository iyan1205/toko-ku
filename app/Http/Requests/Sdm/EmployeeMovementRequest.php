<?php

namespace App\Http\Requests\Sdm;

use App\Enums\EmployeeMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.employees.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(EmployeeMovementType::class)],
            'effective_date' => ['required', 'date', 'after:2000-01-01'],
            'decree_number' => ['nullable', 'string', 'max:50'],
            'to_branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'to_department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'to_position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')],
            'to_supervisor_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'to_grade' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'type' => 'jenis', 'effective_date' => 'tanggal efektif', 'decree_number' => 'nomor SK',
            'to_branch_id' => 'cabang baru', 'to_department_id' => 'departemen baru', 'to_position_id' => 'jabatan baru',
            'to_supervisor_id' => 'atasan baru', 'to_grade' => 'grade baru', 'notes' => 'catatan',
        ];
    }
}
