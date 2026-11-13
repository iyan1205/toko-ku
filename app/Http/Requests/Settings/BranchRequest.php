<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->route('branch') ? 'settings.branches.update' : 'settings.branches.create');
    }

    /** Kode disimpan huruf besar tanpa spasi di tepi. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? mb_strtoupper(trim((string) $this->input('code'))) : null,
            'is_active' => $this->boolean('is_active'),
            'is_default' => $this->boolean('is_default'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Kode cabang yang sudah dihapus tetap tidak boleh dipakai ulang (dokumen lama bisa merujuknya).
            'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9\-]+$/', Rule::unique('branches', 'code')->ignore($this->route('branch'))],
            'name' => ['required', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]+$/'],
            'is_active' => ['boolean'],
            'is_default' => ['boolean'],
            // Titik dan radius absen (opsional): absen masuk dari ponsel hanya bisa di dalam radius.
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude,attendance_radius_m'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude,attendance_radius_m'],
            'attendance_radius_m' => ['nullable', 'integer', 'min:10', 'max:10000', 'required_with:latitude'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code' => 'kode cabang',
            'name' => 'nama cabang',
            'address' => 'alamat',
            'phone' => 'nomor telepon',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Kode cabang hanya boleh berisi huruf, angka, dan tanda -.',
            'code.unique' => 'Kode cabang sudah dipakai (termasuk oleh cabang yang sudah dihapus).',
            'phone.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, tanda +, -, dan ().',
        ];
    }
}
