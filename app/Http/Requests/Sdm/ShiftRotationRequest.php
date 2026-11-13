<?php

namespace App\Http\Requests\Sdm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftRotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('rotation') ? 'sdm.shifts.update' : 'sdm.shifts.create');
    }

    /** Sel pola: id shift, atau "libur"/kosong = libur (disimpan null). */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'pattern' => array_values(array_map(
                fn ($v) => in_array($v, [null, '', 'libur'], true) ? null : $v,
                (array) $this->input('pattern', []),
            )),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', Rule::unique('shift_rotations', 'name')->ignore($this->route('rotation')?->id)],
            'pattern' => ['required', 'array', 'min:1', 'max:62'],
            'pattern.*' => ['nullable', 'integer', Rule::exists('shifts', 'id')],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama pola', 'pattern' => 'pola', 'pattern.*' => 'shift', 'description' => 'keterangan'];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Nama pola ini sudah ada.', 'pattern.required' => 'Isi minimal satu hari di pola.'];
    }
}
