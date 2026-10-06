<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-suppliers');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'npwp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9.\-]+$/'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama supplier',
            'contact_name' => 'nama kontak',
            'phone' => 'nomor telepon',
            'email' => 'email',
            'address' => 'alamat',
            'npwp' => 'NPWP',
            'note' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, tanda + dan -.',
            'npwp.regex' => 'NPWP hanya boleh berisi angka, titik, dan strip.',
        ];
    }
}
