<?php

namespace App\Http\Requests\Settings;

use App\Enums\ApproverType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alur persetujuan: jenis dokumen, kondisi (semua harus terpenuhi), cabang, dan tahap penyetuju berurutan. */
class ApprovalFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // dijaga middleware permission di route
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'allow_self_approval' => $this->boolean('allow_self_approval'),
            'is_active' => $this->boolean('is_active'),
            'conditions' => array_values(array_filter((array) $this->input('conditions', []), fn ($c) => is_array($c) && filled($c['value'] ?? null))),
            'steps' => array_values((array) $this->input('steps', [])),
        ]);
    }

    public function rules(): array
    {
        $documents = config('approval.documents', []);
        $fields = array_keys($documents[$this->input('document_type')]['fields'] ?? []);

        return [
            'name' => ['required', 'string', 'max:100'],
            'document_type' => ['required', Rule::in(array_keys($documents))],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'priority' => ['required', 'integer', 'between:1,999'],
            'allow_self_approval' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],

            'conditions' => ['array', 'max:5'],
            'conditions.*.field' => ['required', Rule::in($fields)],
            'conditions.*.operator' => ['required', Rule::in(array_keys(config('approval.operators', [])))],
            'conditions.*.value' => ['required', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'],

            'steps' => ['required', 'array', 'min:1', 'max:10'],
            'steps.*.name' => ['required', 'string', 'max:100'],
            'steps.*.approver_type' => ['required', Rule::enum(ApproverType::class)],
            'steps.*.role_id' => ['nullable', 'required_if:steps.*.approver_type,role', 'integer', Rule::exists(config('permission.table_names.roles', 'roles'), 'id')],
            'steps.*.user_id' => ['nullable', 'required_if:steps.*.approver_type,user', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')->where('is_active', true)],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama alur',
            'document_type' => 'jenis dokumen',
            'branch_id' => 'cabang',
            'priority' => 'prioritas',
            'conditions.*.value' => 'nilai kondisi',
            'conditions.*.field' => 'kolom kondisi',
            'steps' => 'tahap',
            'steps.*.name' => 'nama tahap',
            'steps.*.role_id' => 'peran penyetuju',
            'steps.*.user_id' => 'pengguna penyetuju',
        ];
    }

    public function messages(): array
    {
        return [
            'steps.required' => 'Tambahkan minimal satu tahap persetujuan.',
            'steps.*.role_id.required_if' => 'Pilih peran penyetuju untuk tahap ini.',
            'steps.*.user_id.required_if' => 'Pilih pengguna penyetuju untuk tahap ini.',
        ];
    }
}
