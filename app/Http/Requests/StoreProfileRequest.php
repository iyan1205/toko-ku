<?php

namespace App\Http\Requests;

use App\Services\StoreProfileService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'instagram' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._]+$/'],
            'tiktok' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._]+$/'],
            'footer_struk' => ['nullable', 'string', 'max:255'],
            'lebar_struk_mm' => ['required', 'integer', Rule::in(StoreProfileService::LEBAR_DIDUKUNG)],
        ];
    }

    public function attributes(): array
    {
        return [
            'nama_toko' => 'nama toko',
            'nama_pemilik' => 'nama pemilik',
            'alamat' => 'alamat',
            'telepon' => 'nomor telepon',
            'instagram' => 'Instagram',
            'tiktok' => 'TikTok',
            'footer_struk' => 'footer struk',
            'lebar_struk_mm' => 'lebar kertas struk',
        ];
    }

    public function messages(): array
    {
        return [
            'telepon.regex' => 'Nomor telepon hanya boleh berisi angka, spasi, tanda +, -, dan ().',
            'instagram.regex' => 'Username Instagram hanya boleh berisi huruf, angka, titik, dan garis bawah.',
            'tiktok.regex' => 'Username TikTok hanya boleh berisi huruf, angka, titik, dan garis bawah.',
            'lebar_struk_mm.in' => 'Lebar kertas struk harus 58 mm atau 80 mm.',
        ];
    }

    /** Buang tanda @ dan spasi yang sering ikut tersalin saat mengisi username. */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'instagram' => $this->bersihkanUsername($this->input('instagram')),
            'tiktok' => $this->bersihkanUsername($this->input('tiktok')),
        ]);
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
