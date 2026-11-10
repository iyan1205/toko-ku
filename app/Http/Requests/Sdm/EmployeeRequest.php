<?php

namespace App\Http\Requests\Sdm;

use App\Enums\EmploymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('employee') ? 'sdm.employees.update' : 'sdm.employees.create');
    }

    /** Kode disimpan huruf besar; kosong = dibuat otomatis (KRY-0001). */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('employee')?->id;

        return [
            'code' => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('employees', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            // Satu akun login hanya untuk satu karyawan.
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id'), Rule::unique('employees', 'user_id')->ignore($id)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'position_id' => ['nullable', 'integer', Rule::exists('positions', 'id')],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'identity_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'join_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:join_date'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode karyawan',
            'name' => 'nama',
            'user_id' => 'akun login',
            'department_id' => 'departemen',
            'position_id' => 'jabatan',
            'employment_type' => 'status kerja',
            'identity_number' => 'NIK',
            'birth_date' => 'tanggal lahir',
            'join_date' => 'tanggal mulai kerja',
            'end_date' => 'tanggal berhenti',
            'phone' => 'telepon',
            'address' => 'alamat',
            'notes' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kode karyawan hanya boleh berisi huruf, angka, dan tanda -.',
            'code.unique' => 'Kode karyawan ini sudah dipakai.',
            'user_id.unique' => 'Akun login ini sudah tertaut ke karyawan lain.',
            'identity_number.regex' => 'NIK hanya boleh berisi angka.',
            'phone.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, tanda +, -, dan ().',
        ];
    }
}
