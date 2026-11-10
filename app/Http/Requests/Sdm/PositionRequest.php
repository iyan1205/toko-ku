<?php

namespace App\Http\Requests\Sdm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('position') ? 'sdm.departments.update' : 'sdm.departments.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('positions', 'name')->ignore($this->route('position')?->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama jabatan', 'description' => 'deskripsi'];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Nama jabatan ini sudah ada.'];
    }
}
