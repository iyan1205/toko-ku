<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Ganti kata sandi sendiri: wajib memasukkan kata sandi saat ini. */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(8)->letters()->numbers()],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['current_password' => 'kata sandi saat ini', 'password' => 'kata sandi baru'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Kata sandi saat ini salah.',
            'password.different' => 'Kata sandi baru harus berbeda dari kata sandi saat ini.',
        ];
    }
}
