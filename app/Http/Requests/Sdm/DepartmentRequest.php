<?php

namespace App\Http\Requests\Sdm;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('department') ? 'sdm.departments.update' : 'sdm.departments.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('departments', 'name')->ignore($this->route('department')?->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nama departemen', 'description' => 'deskripsi'];
    }

    public function messages(): array
    {
        return ['name.unique' => 'Nama departemen ini sudah ada.'];
    }
}
