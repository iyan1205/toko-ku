<?php

namespace App\Http\Requests\Accounting;

use App\Enums\InvoiceType;
use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Faktur penjualan / tagihan pembelian manual. Jenis dari nama route; aturan baris di InvoiceService. */
class InvoiceRequest extends FormRequest
{
    public function type(): InvoiceType
    {
        return Str::startsWith((string) $this->route()?->getName(), 'sales-invoices.') ? InvoiceType::Sales : InvoiceType::Purchase;
    }

    public function authorize(): bool
    {
        return $this->user()->can($this->type()->permission('create'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $uang = ['nullable', 'numeric', 'min:0', 'max:99999999999.99', 'decimal:0,2'];

        return [
            'aksi' => ['nullable', Rule::in(['draft', 'post'])],
            'customer_id' => [$this->type() === InvoiceType::Sales ? 'required' : 'prohibited', 'nullable', 'integer'],
            'supplier_id' => [$this->type() === InvoiceType::Purchase ? 'required' : 'prohibited', 'nullable', 'integer'],
            'invoice_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'reference' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:255'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.cost_center_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999', 'decimal:0,3'],
            'lines.*.unit_price' => $uang,
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_id' => 'pelanggan',
            'supplier_id' => 'supplier',
            'invoice_date' => 'tanggal',
            'due_date' => 'jatuh tempo',
            'reference' => 'referensi',
            'tax_rate' => 'tarif pajak',
            'lines' => 'baris',
            'lines.*.quantity' => 'jumlah',
            'lines.*.unit_price' => 'harga',
        ];
    }
}
