<?php

namespace App\Http\Requests\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('account') ? 'accounting.coa.update' : 'accounting.coa.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? trim((string) $this->input('code')) : null,
            'is_header' => $this->boolean('is_header'),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->route('account')?->id;

        return [
            // Kode akun yang sudah dihapus tetap tidak boleh dipakai ulang (jurnal lama merujuknya).
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/', Rule::unique('accounts', 'code')->ignore($id)],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'normal_balance' => ['nullable', Rule::enum(NormalBalance::class)],
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('is_header', true)->whereNull('deleted_at'), Rule::notIn(array_filter([$id]))],
            'is_header' => ['boolean'],
            'is_active' => ['boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode akun',
            'name' => 'nama akun',
            'type' => 'kelompok',
            'normal_balance' => 'saldo normal',
            'parent_id' => 'akun induk',
            'description' => 'deskripsi',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kode akun hanya boleh berisi huruf, angka, titik, dan tanda -.',
            'code.unique' => 'Kode akun ini sudah dipakai (termasuk oleh akun yang sudah dihapus).',
            'parent_id.exists' => 'Akun induk harus akun induk (kelompok) yang masih ada.',
        ];
    }
}
