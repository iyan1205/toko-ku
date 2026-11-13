<?php

namespace App\Http\Requests\Sdm;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.attendance-corrections.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
            'clock_in' => ['nullable', 'date_format:H:i', 'required_without:clock_out'],
            'clock_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return ['attendance_date' => 'tanggal', 'clock_in' => 'jam masuk', 'clock_out' => 'jam pulang', 'reason' => 'alasan'];
    }

    public function messages(): array
    {
        return [
            'attendance_date.before_or_equal' => 'Koreksi hanya untuk tanggal yang sudah lewat atau hari ini.',
            'clock_in.required_without' => 'Isi jam masuk, jam pulang, atau keduanya.',
        ];
    }
}
