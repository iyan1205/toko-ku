<?php

namespace App\Http\Requests\Settings;

use App\Enums\DocumentType;
use App\Enums\NumberingReset;
use App\Services\Settings\DocumentNumberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Pola nomor tiap dokumen: formats[{jenis}][pattern] dan formats[{jenis}][reset]. */
class DocumentNumberingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings.numbering.update');
    }

    protected function prepareForValidation(): void
    {
        $formats = $this->input('formats');

        if (is_array($formats)) {
            foreach ($formats as $type => $format) {
                if (is_array($format) && is_string($format['pattern'] ?? null)) {
                    $formats[$type]['pattern'] = trim($format['pattern']);
                }
            }
            $this->merge(['formats' => $formats]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['formats' => ['required', 'array']];

        foreach (DocumentType::cases() as $type) {
            $rules["formats.{$type->value}"] = ['required', 'array'];
            $rules["formats.{$type->value}.pattern"] = ['required', 'string', 'max:40'];
            $rules["formats.{$type->value}.reset"] = ['required', Rule::enum(NumberingReset::class)];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return collect(DocumentType::cases())->flatMap(fn (DocumentType $type) => [
            "formats.{$type->value}.pattern" => "pola nomor {$type->label()}",
            "formats.{$type->value}.reset" => "reset nomor {$type->label()}",
        ])->all();
    }

    /** Aturan pola (penanda, karakter, panjang, kecocokan dengan periode reset) ada di DocumentNumberService. */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $numbers = app(DocumentNumberService::class);

                foreach (DocumentType::cases() as $type) {
                    $key = "formats.{$type->value}";
                    $pattern = $this->input("{$key}.pattern");
                    $reset = NumberingReset::tryFrom((string) $this->input("{$key}.reset"));

                    if ($validator->errors()->has("{$key}.*") || ! is_string($pattern) || $reset === null) {
                        continue;
                    }

                    if ($error = $numbers->patternError($type, $pattern, $reset)) {
                        $validator->errors()->add("{$key}.pattern", $error);
                    }
                }
            },
        ];
    }
}
