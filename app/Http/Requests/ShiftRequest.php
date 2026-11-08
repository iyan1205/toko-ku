<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-schedules');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:50', Rule::unique('shifts', 'nama')->ignore($this->route('shift')?->id)],
            'jam_masuk' => ['required', 'date_format:H:i'],
            // Lebih kecil dari jam masuk = shift malam yang pulang keesokan harinya.
            'jam_pulang' => ['required', 'date_format:H:i', 'different:jam_masuk'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['nama' => 'nama shift', 'jam_masuk' => 'jam masuk', 'jam_pulang' => 'jam pulang'];
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Nama shift ini sudah ada.',
            'jam_pulang.different' => 'Jam pulang tidak boleh sama dengan jam masuk.',
        ];
    }
}
