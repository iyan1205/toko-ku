<?php

namespace App\Http\Requests\Sdm;

use Illuminate\Foundation\Http\FormRequest;

class OvertimeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.overtime.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'overtime_date' => ['required', 'date', 'after_or_equal:'.now()->subDays(31)->toDateString(), 'before_or_equal:'.now()->addDays(31)->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['overtime_date' => 'tanggal lembur', 'start_time' => 'jam mulai', 'end_time' => 'jam selesai', 'reason' => 'pekerjaan/alasan'];
    }

    public function messages(): array
    {
        return [
            'overtime_date.after_or_equal' => 'Lembur yang diajukan paling lama 31 hari ke belakang.',
            'overtime_date.before_or_equal' => 'Lembur paling lama diajukan 31 hari ke depan.',
        ];
    }
}
