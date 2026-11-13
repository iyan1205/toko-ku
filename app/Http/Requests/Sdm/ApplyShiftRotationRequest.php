<?php

namespace App\Http\Requests\Sdm;

use App\Services\Sdm\ScheduleRotationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyShiftRotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.schedules.update');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['overwrite' => $this->boolean('overwrite'), 'skip_holidays' => $this->boolean('skip_holidays')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $length = count($this->route('rotation')->pattern ?? []);

        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date',
                'before_or_equal:'.$this->date('start_date')?->addDays(ScheduleRotationService::MAX_DAYS - 1)->toDateString()],
            'offset' => ['nullable', 'integer', 'min:0', 'max:'.max(0, $length - 1)],
            'overwrite' => ['boolean'],
            'skip_holidays' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['employee_ids' => 'karyawan', 'start_date' => 'tanggal mulai', 'end_date' => 'tanggal selesai', 'offset' => 'mulai dari hari ke'];
    }

    public function messages(): array
    {
        return [
            'employee_ids.required' => 'Pilih minimal satu karyawan.',
            'end_date.before_or_equal' => 'Rentang tanggal paling panjang '.ScheduleRotationService::MAX_DAYS.' hari.',
        ];
    }
}
