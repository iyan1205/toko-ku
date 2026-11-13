<?php

namespace App\Http\Requests;

use App\Enums\Gender;
use App\Enums\StatusAbsensi;
use App\Models\JenisCuti;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Jenis cuti beserta alur persetujuannya: tahap[n][tipe] = peran|orang, lalu peran atau approver_id. */
class JenisCutiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('jenis_cuti') ? 'sdm.leave-types.update' : 'sdm.leave-types.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'is_paid' => $this->has('is_paid') ? $this->boolean('is_paid') : true,
            'requires_document' => $this->boolean('requires_document'),
            'working_days_only' => $this->has('working_days_only') ? $this->boolean('working_days_only') : false,
            'quota_tiers' => array_values(array_filter((array) $this->input('quota_tiers', []), fn ($t) => is_array($t) && filled($t['months'] ?? null) && filled($t['days'] ?? null))),
        ]);
    }

    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:100', Rule::unique('jenis_cutis', 'nama')->ignore($this->route('jenis_cuti')?->id)],
            'kuota_tahunan' => ['nullable', 'integer', 'min:1', 'max:366'],
            'status_absensi' => ['required', Rule::in(array_column(StatusAbsensi::untukCuti(), 'value'))],
            'is_active' => ['boolean'],
            'category' => ['nullable', Rule::in(array_keys(JenisCuti::CATEGORIES))],
            'is_paid' => ['boolean'],
            'requires_document' => ['boolean'],
            'document_after_days' => ['nullable', 'integer', 'min:0', 'max:366'],
            'max_days_per_request' => ['nullable', 'integer', 'min:1', 'max:366'],
            'min_service_months' => ['nullable', 'integer', 'min:0', 'max:600'],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'working_days_only' => ['boolean'],
            'quota_tiers' => ['array', 'max:6'],
            'quota_tiers.*.months' => ['required', 'integer', 'min:0', 'max:600', 'distinct'],
            'quota_tiers.*.days' => ['required', 'integer', 'min:0', 'max:366'],
            'tahap' => ['nullable', 'array', 'max:5'],
            'tahap.*.tipe' => ['required', Rule::in(['peran', 'orang'])],
            'tahap.*.peran' => ['exclude_unless:tahap.*.tipe,peran', 'required', Rule::exists(config('permission.table_names.roles'), 'name')->where('guard_name', 'web'), 'distinct'],
            'tahap.*.approver_id' => [
                'exclude_unless:tahap.*.tipe,orang', 'required', 'integer', 'distinct',
                Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama' => 'nama jenis cuti',
            'kuota_tahunan' => 'kuota per tahun',
            'status_absensi' => 'status di absensi',
            'tahap' => 'tahap persetujuan',
            'quota_tiers.*.months' => 'masa kerja',
            'quota_tiers.*.days' => 'jumlah hari',
            'max_days_per_request' => 'batas hari per pengajuan',
            'min_service_months' => 'masa kerja minimal',
            'tahap.*.peran' => 'peran approver',
            'tahap.*.approver_id' => 'approver',
        ];
    }

    public function messages(): array
    {
        return [
            'nama.unique' => 'Nama jenis cuti ini sudah ada.',
            'tahap.max' => 'Paling banyak 5 tahap persetujuan.',
            'tahap.*.peran.required' => 'Pilih peran approver untuk tahap ini.',
            'tahap.*.peran.distinct' => 'Peran yang sama dipakai di lebih dari satu tahap.',
            'tahap.*.approver_id.required' => 'Pilih orang yang menyetujui tahap ini.',
            'tahap.*.approver_id.distinct' => 'Orang yang sama dipakai di lebih dari satu tahap.',
            'tahap.*.approver_id.exists' => 'Approver harus pengguna yang masih aktif.',
        ];
    }
}
