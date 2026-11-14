<?php

namespace App\Http\Controllers\Reports;

use App\Exports\ListExport;
use App\Exports\Reports\BatchStockCardExport;
use App\Exports\Reports\InventoryValuationExport;
use App\Exports\Reports\SlowMovingExport;
use App\Exports\Reports\StockTurnoverExport;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laporan → Stok (Fase 16 poin 9): nilai persediaan per tanggal, perputaran stok, barang lambat & mati,
 * dan kartu stok per batch. Setiap laporan adalah ListExport, jadi isi Excel/PDF sama dengan layar.
 * Izin reports.stock.view (halaman) dan reports.stock.export (ekspor) dipasang di routes/modules/reports.php.
 */
class StockReportController extends Controller
{
    /** Slug URL ekspor => metode filter & kelas laporan. */
    private const REPORTS = [
        'nilai-persediaan' => ['valuationFilter', InventoryValuationExport::class],
        'perputaran' => ['turnoverFilter', StockTurnoverExport::class],
        'lambat-mati' => ['slowMovingFilter', SlowMovingExport::class],
        'kartu-batch' => ['batchCardFilter', BatchStockCardExport::class],
    ];

    public function valuation(Request $request): View
    {
        $filter = $this->valuationFilter($request);
        $list = InventoryValuationExport::fromRequest($request, $filter);

        return view('reports.stock.valuation', [
            'list' => $list,
            'rows' => $list->paginate(25),
            'totals' => $list->totals(),
            'filter' => $filter,
        ] + $this->options());
    }

    public function turnover(Request $request): View
    {
        $filter = $this->turnoverFilter($request);
        $list = StockTurnoverExport::fromRequest($request, $filter);

        return view('reports.stock.turnover', [
            'list' => $list,
            'rows' => $list->paginate(25),
            'totals' => $list->totals(),
            'filter' => $filter,
        ] + $this->options());
    }

    public function slowMoving(Request $request): View
    {
        $filter = $this->slowMovingFilter($request);
        $list = SlowMovingExport::fromRequest($request, $filter);

        return view('reports.stock.slow-moving', [
            'list' => $list,
            'rows' => $list->paginate(25),
            'summary' => $list->summary(),
            'filter' => $filter,
        ] + $this->options());
    }

    public function batchCard(Request $request): View
    {
        $filter = $this->batchCardFilter($request);
        $list = BatchStockCardExport::fromRequest($request, $filter);

        return view('reports.stock.batch-card', [
            'list' => $list,
            'rows' => $list->paginate(25),
            'lots' => $list->lots(),
            'produkDipilih' => isset($filter['produk']) ? Product::withTrashed()->with('unit:id,name')->find($filter['produk']) : null,
            'produkBatch' => Product::query()->where('track_batches', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'filter' => $filter,
        ] + $this->options());
    }

    public function export(Request $request, string $report): Response
    {
        [$method, $class] = self::REPORTS[$report] ?? abort(404);

        return $class::fromRequest($request, $this->{$method}($request))->download(ListExport::formatFrom($request));
    }

    /** @return array{gudang: Collection<int, Warehouse>, kategori: Collection<int|string, string>} */
    private function options(): array
    {
        return [
            'gudang' => Warehouse::query()->inCurrentBranch()->ordered()->get(['id', 'name']),
            'kategori' => Category::query()->orderBy('name')->pluck('name', 'id'),
        ];
    }

    /** Filter umum daftar produk: gudang, kategori, cari, urutan. @return array<string, array<int, mixed>> */
    private function common(): array
    {
        return [
            'gudang' => ['nullable', 'integer'],
            'kategori' => ['nullable', 'integer'],
            'cari' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string'],
            'dir' => ['nullable', 'in:asc,desc'],
        ];
    }

    /** Tanggal bawaan ditulis ke filter supaya ekspor di latar belakang memakai tanggal yang sama. @return array<string, mixed> */
    private function valuationFilter(Request $request): array
    {
        $f = $request->validate(['per' => ['nullable', 'date']] + $this->common());

        return array_filter($f, fn ($v) => $v !== null) + ['per' => today()->toDateString()];
    }

    /** @return array<string, mixed> */
    private function turnoverFilter(Request $request): array
    {
        $f = $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ] + $this->common());

        return array_filter($f, fn ($v) => $v !== null) + [
            'dari' => today()->subMonthsNoOverflow(2)->startOfMonth()->toDateString(),
            'sampai' => today()->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function slowMovingFilter(Request $request): array
    {
        $f = $request->validate([
            'hari' => ['nullable', 'integer', Rule::in(SlowMovingExport::DAYS)],
            'status' => ['nullable', Rule::in(['semua', ...array_keys(SlowMovingExport::STATUSES)])],
        ] + $this->common());

        return array_filter($f, fn ($v) => $v !== null) + ['hari' => 90];
    }

    /** @return array<string, mixed> */
    private function batchCardFilter(Request $request): array
    {
        $f = $request->validate([
            'produk' => ['nullable', 'integer'],
            'lot' => ['nullable', 'integer'],
            'gudang' => ['nullable', 'integer'],
            'cari' => ['nullable', 'string', 'max:50'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
            'sort' => ['nullable', 'string'],
            'dir' => ['nullable', 'in:asc,desc'],
        ]);

        return array_filter($f, fn ($v) => $v !== null);
    }
}
