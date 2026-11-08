<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\View;

/**
 * Katalog komponen UI (resources/views/components/ui) untuk pengembang.
 *
 * Hanya tersedia di environment local dan hanya untuk Super Admin. Di environment lain
 * halaman ini selalu 404 (bahkan untuk Super Admin) agar keberadaannya tidak terlihat.
 */
class UiKitController extends Controller
{
    /** Kolom tabel contoh yang boleh dipakai untuk mengurutkan. */
    private const SORTABLE_COLUMNS = ['name', 'price', 'stock'];

    private const PER_PAGE = 5;

    public function __invoke(Request $request): View
    {
        abort_unless(app()->isLocal(), 404);
        abort_unless($request->user()->isSuperAdmin(), 403);

        // Contoh pesan error agar tampilan isian yang bermasalah bisa dilihat.
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'contoh_salah' => 'Nama produk wajib diisi.',
        ])));

        return view('dev.ui-kit', [
            'products' => $this->sampleProducts($request),
            'categories' => ['Makanan' => 'Makanan', 'Minuman' => 'Minuman', 'Bahan baku' => 'Bahan baku'],
        ]);
    }

    /**
     * Data contoh (bukan dari database) untuk mencoba pencarian, filter, urutan, dan pagination tabel.
     *
     * @return LengthAwarePaginator<int, array{name: string, sku: string, category: string, price: int, stock: int, min_stock: int}>
     */
    private function sampleProducts(Request $request): LengthAwarePaginator
    {
        $sort = in_array($request->query('sort'), self::SORTABLE_COLUMNS, true) ? $request->query('sort') : 'name';
        $descending = $request->query('dir') === 'desc';
        $search = mb_strtolower(trim((string) $request->query('cari', '')));
        $category = (string) $request->query('kategori', '');

        $rows = collect([
            ['name' => 'Ayam Geprek', 'sku' => 'MKN-001', 'category' => 'Makanan', 'price' => 15000, 'stock' => 42, 'min_stock' => 10],
            ['name' => 'Ayam Goreng Dada', 'sku' => 'MKN-002', 'category' => 'Makanan', 'price' => 13000, 'stock' => 8, 'min_stock' => 10],
            ['name' => 'Ayam Goreng Paha', 'sku' => 'MKN-003', 'category' => 'Makanan', 'price' => 12000, 'stock' => 0, 'min_stock' => 10],
            ['name' => 'Nasi Putih', 'sku' => 'MKN-004', 'category' => 'Makanan', 'price' => 5000, 'stock' => 120, 'min_stock' => 20],
            ['name' => 'Tahu Goreng', 'sku' => 'MKN-005', 'category' => 'Makanan', 'price' => 2000, 'stock' => 35, 'min_stock' => 15],
            ['name' => 'Tempe Goreng', 'sku' => 'MKN-006', 'category' => 'Makanan', 'price' => 2000, 'stock' => 12, 'min_stock' => 15],
            ['name' => 'Sambal Bawang', 'sku' => 'MKN-007', 'category' => 'Makanan', 'price' => 3000, 'stock' => 60, 'min_stock' => 10],
            ['name' => 'Es Teh Manis', 'sku' => 'MNM-001', 'category' => 'Minuman', 'price' => 4000, 'stock' => 80, 'min_stock' => 20],
            ['name' => 'Es Jeruk', 'sku' => 'MNM-002', 'category' => 'Minuman', 'price' => 6000, 'stock' => 25, 'min_stock' => 20],
            ['name' => 'Air Mineral 600 ml', 'sku' => 'MNM-003', 'category' => 'Minuman', 'price' => 4000, 'stock' => 3, 'min_stock' => 24],
            ['name' => 'Tepung Bumbu 1 kg', 'sku' => 'BHN-001', 'category' => 'Bahan baku', 'price' => 18000, 'stock' => 14, 'min_stock' => 5],
            ['name' => 'Minyak Goreng 2 L', 'sku' => 'BHN-002', 'category' => 'Bahan baku', 'price' => 34000, 'stock' => 6, 'min_stock' => 4],
        ])
            ->when($search !== '', fn ($rows) => $rows->filter(fn ($row) => str_contains(mb_strtolower($row['name'].' '.$row['sku']), $search)))
            ->when($category !== '', fn ($rows) => $rows->where('category', $category))
            ->sortBy($sort, $sort === 'name' ? SORT_NATURAL | SORT_FLAG_CASE : SORT_REGULAR, $descending)
            ->values();

        $page = max(1, (int) $request->query('page', 1));

        return new LengthAwarePaginator(
            $rows->forPage($page, self::PER_PAGE)->values(),
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );
    }
}
