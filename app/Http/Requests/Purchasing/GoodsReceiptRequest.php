<?php

namespace App\Http\Requests\Purchasing;

use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Penerimaan barang dari PO. items = purchase_order_item_id => jumlah diterima. */
class GoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('purchasing.receipts.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['update_cost' => $this->boolean('update_cost')]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $supplierId = $this->route('purchaseOrder')?->supplier_id;

        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'received_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'due_date' => ['required', 'date', 'after_or_equal:received_date'],
            'delivery_note_number' => ['nullable', 'string', 'max:50'],
            // Faktur yang sama dari supplier yang sama hanya boleh tercatat sekali.
            'supplier_invoice_number' => [
                'nullable', 'string', 'max:50',
                Rule::unique('purchases', 'supplier_invoice_number')->where(fn ($q) => $q->where('supplier_id', $supplierId)->where('status', 'completed')),
            ],
            'note' => ['nullable', 'string', 'max:255'],
            'update_cost' => ['boolean'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0', 'max:99999.999', 'decimal:0,3'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang tujuan',
            'received_date' => 'tanggal terima',
            'due_date' => 'jatuh tempo',
            'delivery_note_number' => 'nomor surat jalan',
            'supplier_invoice_number' => 'nomor faktur supplier',
            'items.*' => 'jumlah diterima',
        ];
    }

    public function messages(): array
    {
        return [
            'received_date.before_or_equal' => 'Tanggal terima tidak boleh lebih dari hari ini.',
            'supplier_invoice_number.unique' => 'Nomor faktur ini sudah tercatat untuk supplier yang sama.',
        ];
    }
}
