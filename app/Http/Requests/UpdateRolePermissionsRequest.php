<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Simpan centang matriks hak akses untuk satu peran. Tanpa centang sama sekali = semua dicabut. */
class UpdateRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updatePermissions', $this->route('role'));
    }

    /** Formulir tanpa centang tidak mengirim permissions sama sekali: anggap daftar kosong. */
    protected function prepareForValidation(): void
    {
        $this->merge(['permissions' => $this->input('permissions', [])]);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'permissions' => ['array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionCatalog::all())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['permissions.*.in' => 'Ada hak akses yang tidak dikenal. Muat ulang halaman.'];
    }
}
