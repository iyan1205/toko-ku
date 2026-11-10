<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
            'is_active' => $this->boolean('is_active'),
        ]);

        if ($this->has('all_branches')) {
            $this->merge(['all_branches' => $this->boolean('all_branches')]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'role' => ['required', 'string', new AssignableRole($this->user())],
            'is_active' => ['boolean'],
            // Akses cabang: hanya dikirim formulir bila toko punya lebih dari satu cabang.
            'all_branches' => ['sometimes', 'boolean'],
            'branches' => [Rule::requiredIf(fn () => $this->has('all_branches') && ! $this->boolean('all_branches')), 'array'],
            'branches.*' => ['integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'phone' => 'nomor HP',
            'password' => 'kata sandi',
            'role' => 'peran',
        ];
    }

    public function messages(): array
    {
        return [
            'branches.required' => 'Pilih minimal satu cabang, atau centang Semua cabang.',
            'phone.regex' => 'Nomor HP hanya boleh berisi angka, spasi, tanda + dan -.',
            'email.unique' => 'Email ini sudah terdaftar.',
        ];
    }
}
