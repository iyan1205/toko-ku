<?php

namespace App\Http\Requests;

use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('shift') ? 'sdm.shifts.update' : 'sdm.shifts.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'break_minutes' => $this->filled('break_minutes') ? $this->input('break_minutes') : 0,
        ]);
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:50', Rule::unique('shifts', 'nama')->ignore($this->route('shift')?->id)],
            'jam_masuk' => ['required', 'date_format:H:i'],
            // Lebih kecil dari jam masuk = shift malam yang pulang keesokan harinya.
            'jam_pulang' => ['required', 'date_format:H:i', 'different:jam_masuk'],
            // Kosong = ikut bawaan Pengaturan → SDM.
            'late_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'early_leave_tolerance_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'color' => ['nullable', Rule::in(array_keys(Shift::COLORS))],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama' => 'nama shift', 'jam_masuk' => 'jam masuk', 'jam_pulang' => 'jam pulang',
            'late_tolerance_minutes' => 'toleransi telat', 'early_leave_tolerance_minutes' => 'toleransi pulang cepat',
            'break_minutes' => 'istirahat',
        ];
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Nama shift ini sudah ada.',
            'jam_pulang.different' => 'Jam pulang tidak boleh sama dengan jam masuk.',
        ];
    }
}
