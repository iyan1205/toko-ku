<?php

namespace App\Http\Requests\Settings;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.store.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'nama_toko' => ['required', 'string', 'max:100'],
            'nama_pemilik' => ['nullable', 'string', 'max:100'],
            'alamat' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'string', 'email', 'max:100'],
            'npwp' => ['nullable', 'string', 'max:25', 'regex:/^[0-9.\-\s]+$/', $this->npwpDigits(...)],
            'instagram' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._]+$/'],
            'tiktok' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._]+$/'],
            // SVG sengaja tidak diterima: bisa berisi skrip.
            'logo' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(1024)],
            'hapus_logo' => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama_toko' => 'nama toko',
            'nama_pemilik' => 'nama pemilik',
            'alamat' => 'alamat',
            'telepon' => 'nomor telepon',
            'email' => 'email',
            'npwp' => 'NPWP',
            'instagram' => 'Instagram',
            'tiktok' => 'TikTok',
            'logo' => 'logo',
        ];
    }

    public function messages(): array
    {
        return [
            'telepon.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, tanda +, -, dan ().',
            'npwp.regex' => 'NPWP hanya boleh berisi angka, titik, dan tanda -.',
            'instagram.regex' => 'Username Instagram hanya boleh berisi huruf, angka, titik, dan garis bawah.',
            'tiktok.regex' => 'Username TikTok hanya boleh berisi huruf, angka, titik, dan garis bawah.',
            'logo.max' => 'Ukuran logo maksimal 1 MB.',
        ];
    }

    /** Buang tanda @ dan spasi yang sering ikut tersalin saat mengisi username. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'instagram' => $this->bersihkanUsername($this->input('instagram')),
            'tiktok' => $this->bersihkanUsername($this->input('tiktok')),
            'hapus_logo' => $this->boolean('hapus_logo'),
        ]);
    }

    /** NPWP lama 15 digit, NPWP baru (sama dengan NIK) 16 digit. */
    private function npwpDigits(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = strlen((string) preg_replace('/\D/', '', (string) $value));

        if (! in_array($digits, [15, 16], true)) {
            $fail('NPWP harus 15 atau 16 digit.');
        }
    }

    private function bersihkanUsername(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $bersih = ltrim(trim((string) $nilai), '@');

        return $bersih === '' ? null : $bersih;
    }
}
