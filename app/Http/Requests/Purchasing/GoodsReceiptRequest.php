<?php

namespace App\Http\Requests\Purchasing;

use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            // Batch & kedaluwarsa / nomor seri per baris PO (produk yang melacaknya).
            'batches' => ['nullable', 'array'],
            'batches.*.batch_number' => ['nullable', 'string', 'max:50'],
            'batches.*.expiry_date' => ['nullable', 'date'],
            'batches.*.serials' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** Produk dengan batch wajib diisi nomor batch; produk bernomor seri wajib diisi seri sebanyak jumlahnya. */
    public function after(): array
    {
        return [function (Validator $validator) {
            $order = $this->route('purchaseOrder');
            if ($order === null || $validator->errors()->isNotEmpty()) {
                return;
            }
            $items = $order->items()->with('product:id,name,track_batches,track_serials')->get()->keyBy('id');
            foreach ((array) $this->input('items', []) as $id => $qty) {
                $item = $items[(int) $id] ?? null;
                if ($item === null || (float) $qty <= 0 || $item->product === null) {
                    continue;
                }
                $batch = (array) $this->input("batches.{$id}", []);
                if ($item->product->track_batches && blank($batch['batch_number'] ?? null)) {
                    $validator->errors()->add("batches.{$id}.batch_number", "Isi nomor batch {$item->product_name}.");
                }
                if ($item->product->track_serials) {
                    $serials = preg_split('/[\s,;]+/', (string) ($batch['serials'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                    if (count(array_unique($serials)) !== (int) $qty || floor((float) $qty) != (float) $qty) {
                        $validator->errors()->add("batches.{$id}.serials", "Isi {$qty} nomor seri {$item->product_name} (dipisah koma atau baris baru).");
                    }
                }
            }
        }];
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
