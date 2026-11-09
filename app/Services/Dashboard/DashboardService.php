<?php

namespace App\Services\Dashboard;

use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\User;
use App\Reports\LaporanPenjualan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Isi dashboard sesuai hak akses pengguna. Setiap bagian bernilai null bila pengguna
 * tidak boleh melihatnya, dan view hanya menampilkan bagian yang tidak null.
 *
 *   - Ringkasan penjualan toko, grafik, produk terlaris: dashboard.overview.view
 *   - Ringkasan penjualan milik sendiri (kasir): pos.transactions.view-own
 *   - Transaksi terakhir: pos.transactions.view (semua) atau view-own (milik sendiri)
 *   - Stok menipis: inventory.stock.view
 *   - Hutang jatuh tempo: purchasing.payables.view
 *
 * Angka penjualan memakai LaporanPenjualan, jadi sama dengan halaman Laporan
 * (hanya transaksi selesai; yang dibatalkan tidak dihitung).
 */
class DashboardService
{
    /** Rentang grafik terpanjang (hari). Grafik 7 hari memakai data yang sama. */
    public const CHART_DAYS = 30;

    private const RECENT_LIMIT = 6;

    private const LOW_STOCK_LIMIT = 5;

    private const TOP_PRODUCTS_LIMIT = 5;

    /** Hutang yang jatuh tempo dalam sekian hari ke depan ikut ditampilkan sebagai peringatan. */
    public const PAYABLE_WARNING_DAYS = 7;

    /**
     * @return array{
     *   salesScope: 'store'|'own'|null,
     *   sales: array{today_total: float, today_count: int, yesterday_total: float, average: float}|null,
     *   chart: list<array{date: string, label: string, title: string, total: float, count: int}>|null,
     *   topProducts: array<int, Collection<int, array<string, mixed>>>|null,
     *   recentScope: 'all'|'own'|null,
     *   recentSales: Collection<int, Sale>|null,
     *   lowStock: array{count: int, items: Collection<int, Product>}|null,
     *   payables: array{overdue_total: float, overdue_count: int, upcoming_total: float, upcoming_count: int}|null,
     * }
     */
    public function for(User $user): array
    {
        $today = CarbonImmutable::today();

        $salesScope = match (true) {
            $user->can('dashboard.overview.view') => 'store',
            $user->can('pos.transactions.view-own') => 'own',
            default => null,
        };

        $recentScope = match (true) {
            $user->can('pos.transactions.view') => 'all',
            $user->can('pos.transactions.view-own') => 'own',
            default => null,
        };

        $daily = $salesScope === null ? null : $this->dailySales(
            $salesScope === 'store' ? self::CHART_DAYS : 2,
            $salesScope === 'own' ? $user->getKey() : null,
            $today,
        );

        return [
            'salesScope' => $salesScope,
            'sales' => $daily === null ? null : $this->salesSummary($daily),
            'chart' => $salesScope === 'store' ? $daily : null,
            'topProducts' => $salesScope === 'store' ? [
                7 => $this->topProducts(7, $today),
                30 => $this->topProducts(30, $today),
            ] : null,
            'recentScope' => $recentScope,
            'recentSales' => $recentScope === null ? null : $this->recentSales($recentScope === 'own' ? $user : null),
            'lowStock' => $user->can('inventory.stock.view') ? $this->lowStock() : null,
            'payables' => $user->can('purchasing.payables.view') ? $this->payables($today) : null,
        ];
    }

    /**
     * Omzet dan jumlah transaksi per hari, termasuk hari tanpa penjualan (nol), urut dari yang terlama.
     *
     * @return list<array{date: string, label: string, title: string, total: float, count: int}>
     */
    private function dailySales(int $days, ?int $cashierId, CarbonImmutable $today): array
    {
        $from = $today->subDays($days - 1);
        $rows = (new LaporanPenjualan($from->startOfDay(), $today->endOfDay(), $cashierId))->harian()->keyBy('tanggal');

        $result = [];
        for ($date = $from; $date->lte($today); $date = $date->addDay()) {
            $row = $rows[$date->toDateString()] ?? null;
            $result[] = [
                'date' => $date->toDateString(),
                'label' => $date->locale('id')->translatedFormat('d M'),
                'title' => $date->locale('id')->translatedFormat('l, d M Y'),
                'total' => (float) ($row['omzet'] ?? 0),
                'count' => (int) ($row['jumlah'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array{total: float, count: int}>  $daily
     * @return array{today_total: float, today_count: int, yesterday_total: float, average: float}
     */
    private function salesSummary(array $daily): array
    {
        $today = $daily[count($daily) - 1];
        $yesterday = $daily[count($daily) - 2] ?? ['total' => 0.0, 'count' => 0];

        return [
            'today_total' => $today['total'],
            'today_count' => $today['count'],
            'yesterday_total' => $yesterday['total'],
            'average' => $today['count'] > 0 ? $today['total'] / $today['count'] : 0.0,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function topProducts(int $days, CarbonImmutable $today): Collection
    {
        return (new LaporanPenjualan($today->subDays($days - 1)->startOfDay(), $today->endOfDay()))
            ->produkTeratas(self::TOP_PRODUCTS_LIMIT);
    }

    /** @return Collection<int, Sale> termasuk yang dibatalkan (ditandai di tampilan) */
    private function recentSales(?User $cashier): Collection
    {
        return Sale::query()
            ->with(['customer:id,name', 'user:id,name'])
            ->when($cashier, fn ($q, User $u) => $q->where('user_id', $u->getKey()))
            ->latest('sold_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /** @return array{count: int, items: Collection<int, Product>} */
    private function lowStock(): array
    {
        return [
            'count' => Product::query()->needsRestock()->count(),
            'items' => Product::query()->needsRestock()
                ->with('unit:id,name')
                ->orderBy('stock')->orderBy('name')
                ->limit(self::LOW_STOCK_LIMIT)
                ->get(['id', 'name', 'stock', 'min_stock', 'unit_id']),
        ];
    }

    /**
     * Sisa hutang pembelian yang sudah jatuh tempo (termasuk hari ini) dan yang jatuh tempo
     * dalam PAYABLE_WARNING_DAYS hari ke depan. Pembelian tanpa tanggal jatuh tempo tidak dihitung.
     *
     * @return array{overdue_total: float, overdue_count: int, upcoming_total: float, upcoming_count: int}
     */
    private function payables(CarbonImmutable $today): array
    {
        $open = fn () => Purchase::query()
            ->where('status', PurchaseStatus::Completed->value)
            ->where('balance', '>', 0)
            ->whereNotNull('due_date');

        $overdue = $open()->whereDate('due_date', '<=', $today)
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(balance), 0) as total')->first();
        $upcoming = $open()
            ->whereDate('due_date', '>', $today)
            ->whereDate('due_date', '<=', $today->addDays(self::PAYABLE_WARNING_DAYS))
            ->selectRaw('COUNT(*) as jumlah, COALESCE(SUM(balance), 0) as total')->first();

        return [
            'overdue_total' => (float) $overdue->total,
            'overdue_count' => (int) $overdue->jumlah,
            'upcoming_total' => (float) $upcoming->total,
            'upcoming_count' => (int) $upcoming->jumlah,
        ];
    }
}
