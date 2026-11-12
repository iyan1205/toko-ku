<?php

namespace App\Http\Requests\Sdm;

use App\Enums\EducationLevel;
use App\Enums\EmploymentType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PtkpStatus;
use App\Models\Employee;
use App\Services\Sdm\EmployeeService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('employee') ? 'sdm.employees.update' : 'sdm.employees.create');
    }

    /** Kode disimpan huruf besar; kosong = dibuat otomatis (KRY-0001). Baris keluarga/pendidikan/pengalaman yang kosong dibuang. */
    protected function prepareForValidation(): void
    {
        $rows = fn (string $key, string $required) => array_values(array_filter(
            (array) $this->input($key, []),
            fn ($row) => is_array($row) && trim((string) ($row[$required] ?? '')) !== '',
        ));

        $this->merge([
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
            'is_active' => $this->boolean('is_active'),
            'family' => array_map(fn ($r) => $r + ['is_dependent' => false], $rows('family', 'name')),
            'educations' => $rows('educations', 'institution'),
            'experiences' => $rows('experiences', 'company'),
            'tax_id' => $this->filled('tax_id') ? preg_replace('/[^0-9]/', '', (string) $this->input('tax_id')) : null,
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
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'supervisor_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at'), $this->supervisorRule()],
            'grade' => ['nullable', 'string', 'max:20'],
            'birth_place' => ['nullable', 'string', 'max:100'],
            'religion' => ['nullable', Rule::in(config('sdm.religions'))],
            'marital_status' => ['nullable', Rule::enum(MaritalStatus::class)],
            'blood_type' => ['nullable', Rule::in(config('sdm.blood_types'))],
            'tax_id' => ['nullable', 'string', 'regex:/^[0-9]{15,16}$/'],
            'ptkp_status' => ['nullable', Rule::enum(PtkpStatus::class)],
            'bpjs_health_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'bpjs_employment_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_number' => ['nullable', 'string', 'max:40', 'regex:/^[0-9\-\s]+$/'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:50'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]+$/'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_photo' => ['nullable', 'boolean'],
            'family' => ['array', 'max:20'],
            'family.*.name' => ['required', 'string', 'max:150'],
            'family.*.relation' => ['required', Rule::enum(FamilyRelation::class)],
            'family.*.identity_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+$/'],
            'family.*.birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'family.*.occupation' => ['nullable', 'string', 'max:100'],
            'family.*.is_dependent' => ['boolean'],
            'family.*.phone' => ['nullable', 'string', 'max:25'],
            'educations' => ['array', 'max:20'],
            'educations.*.level' => ['required', Rule::enum(EducationLevel::class)],
            'educations.*.institution' => ['required', 'string', 'max:150'],
            'educations.*.major' => ['nullable', 'string', 'max:100'],
            'educations.*.start_year' => ['nullable', 'integer', 'between:1950,2100'],
            'educations.*.end_year' => ['nullable', 'integer', 'between:1950,2100', 'gte:educations.*.start_year'],
            'experiences' => ['array', 'max:20'],
            'experiences.*.company' => ['required', 'string', 'max:150'],
            'experiences.*.position' => ['nullable', 'string', 'max:100'],
            'experiences.*.start_date' => ['nullable', 'date'],
            'experiences.*.end_date' => ['nullable', 'date', 'after_or_equal:experiences.*.start_date'],
            'experiences.*.description' => ['nullable', 'string', 'max:255'],
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

    /** Atasan tidak boleh diri sendiri atau bawahannya (struktur melingkar). */
    private function supervisorRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $employee = $this->route('employee');
            if ($employee instanceof Employee && in_array((int) $value, [$employee->id, ...app(EmployeeService::class)->descendantIds($employee)], true)) {
                $fail('Atasan tidak boleh karyawan itu sendiri atau bawahannya.');
            }
        };
    }

    public function attributes(): array
    {
        return [
            'gender' => 'jenis kelamin',
            'branch_id' => 'cabang',
            'supervisor_id' => 'atasan langsung',
            'birth_place' => 'tempat lahir',
            'religion' => 'agama',
            'marital_status' => 'status pernikahan',
            'blood_type' => 'golongan darah',
            'tax_id' => 'NPWP',
            'ptkp_status' => 'status PTKP',
            'bpjs_health_number' => 'nomor BPJS Kesehatan',
            'bpjs_employment_number' => 'nomor BPJS Ketenagakerjaan',
            'bank_account_number' => 'nomor rekening',
            'bank_account_name' => 'nama pemilik rekening',
            'emergency_contact_phone' => 'telepon kontak darurat',
            'photo' => 'foto',
            'family.*.name' => 'nama anggota keluarga',
            'family.*.relation' => 'hubungan keluarga',
            'family.*.birth_date' => 'tanggal lahir anggota keluarga',
            'educations.*.institution' => 'nama sekolah/kampus',
            'educations.*.end_year' => 'tahun lulus',
            'experiences.*.company' => 'nama perusahaan',
            'experiences.*.end_date' => 'tanggal selesai pengalaman',
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
            'tax_id.regex' => 'NPWP terdiri dari 15 atau 16 angka.',
            'bpjs_health_number.regex' => 'Nomor BPJS hanya boleh berisi angka.',
            'bpjs_employment_number.regex' => 'Nomor BPJS hanya boleh berisi angka.',
        ];
    }
}
