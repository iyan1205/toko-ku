<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\ProductSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cari produk (JSON) untuk formulir dokumen: transfer, purchase order, pembelian.
 * Dipasang di beberapa route dengan izin masing-masing formulir (lihat routes/modules/*.php).
 * ?q= kata kunci/barcode, ?gudang= stok di gudang itu, ?ids=1,2 muat ulang produk tertentu.
 */
class ProductLookupController extends Controller
{
    public function __invoke(Request $request, ProductSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'gudang' => ['nullable', 'integer'],
            'ids' => ['nullable', 'string', 'max:2000'],
        ]);

        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($data['ids'] ?? '')))));

        return response()->json($search->search($data['q'] ?? null, $data['gudang'] ?? null, $ids));
    }
}
