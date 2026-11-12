<?php

namespace App\Http\Requests\Sdm;

use App\Services\Documents\AttachmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sdm.employees.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $files = app(AttachmentService::class);

        return [
            'type' => ['required', Rule::in(array_keys(config('sdm.document_types')))],
            'name' => ['required', 'string', 'max:150'],
            'number' => ['nullable', 'string', 'max:60'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'notes' => ['nullable', 'string', 'max:255'],
            'file' => ['nullable', 'file', 'mimes:'.implode(',', $files->allowedExtensions()), 'max:'.$files->maxKilobytes()],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('name') && $this->filled('type')) {
            $this->merge(['name' => config('sdm.document_types.'.$this->input('type'))]);
        }
    }

    public function attributes(): array
    {
        return ['type' => 'jenis dokumen', 'name' => 'nama dokumen', 'number' => 'nomor', 'issued_at' => 'tanggal terbit', 'expires_at' => 'berlaku sampai', 'file' => 'berkas'];
    }
}
