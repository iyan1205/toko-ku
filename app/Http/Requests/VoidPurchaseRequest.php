<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('void-purchase');
    }

    public function rules(): array
    {
        return ['alasan' => ['required', 'string', 'min:5', 'max:255']];
    }

    public function attributes(): array
    {
        return ['alasan' => 'alasan pembatalan'];
    }
}
