<?php

namespace App\Http\Requests;

use App\Rules\OpenPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Simpan hitungan opname. aksi=draft menyimpan tanpa mengubah stok; aksi=final langsung
 * membukukan selisih dan butuh izin inventory.opnames.approve.
 */
class StockOpnameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('inventory.opnames.create')
            && ($this->input('aksi') !== 'final' || $this->user()->can('inventory.opnames.approve'));
    }

    public function rules(): array
    {
        return [
            'aksi' => ['required', Rule::in(['draft', 'final'])],
            // Gudang hanya dipilih saat membuat; draft tidak bisa pindah gudang.
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'opname_date' => ['required', 'date', 'before_or_equal:today', new OpenPeriod],
            'note' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.system_stock' => ['required', 'numeric'],
            'items.*.physical_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'opname_date' => 'tanggal opname',
            'note' => 'catatan',
            'items' => 'daftar produk',
            'items.*.physical_stock' => 'stok fisik',
            'items.*.reason' => 'alasan selisih',
        ];
    }

    public function messages(): array
    {
        return [
            'opname_date.before_or_equal' => 'Tanggal opname tidak boleh lebih dari hari ini.',
            'items.*.physical_stock.min' => 'Stok fisik tidak boleh negatif.',
        ];
    }
}
