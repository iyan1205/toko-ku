<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-customers');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama pelanggan',
            'phone' => 'nomor HP',
            'email' => 'email',
            'address' => 'alamat',
            'note' => 'catatan',
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'Nomor HP hanya boleh berisi angka, spasi, tanda + dan -.'];
    }
}
