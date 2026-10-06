<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ImportProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('import', Product::class);
    }

    public function rules(): array
    {
        return ['file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:5120']];
    }

    public function attributes(): array
    {
        return ['file' => 'berkas'];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'Berkas harus berformat XLSX, XLS, atau CSV.',
            'file.max' => 'Ukuran berkas maksimal 5 MB.',
        ];
    }
}
