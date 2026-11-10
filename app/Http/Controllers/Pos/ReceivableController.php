<?php

namespace App\Http\Controllers\Pos;

use App\Enums\PaymentMethod;
use App\Exports\ListExport;
use App\Exports\Pos\ReceivablesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CustomerPaymentRequest;
use App\Models\Customer;
use App\Models\Sale;
use App\Services\PaymentService;
use App\Services\Pos\CashSessionService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Piutang pelanggan: penjualan yang masih punya sisa piutang, dan pelunasannya.
 * Pelunasan memakai PaymentService::terimaPenjualan() (mengunci transaksi, menolak lebih bayar).
 * Uang yang diterima kasir dengan sesi terbuka masuk ke rekap sesinya.
 */
class ReceivableController extends Controller
{
    public function index(Request $request): View
    {
        $list = ReceivablesExport::fromRequest($request, $this->filter($request));
        $batas = CarbonImmutable::today()->subDays(30);
        $open = fn () => Sale::query()->outstanding();

        return view('pos.receivables.index', [
            'list' => $list,
            'receivables' => $list->paginate(15),
            'customers' => Customer::query()->where('receivable_balance', '>', 0)->orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::untukPembelian(),
            'summary' => [
                'total' => (float) $open()->sum('balance'),
                'count' => $open()->count(),
                'customers' => $open()->distinct()->count('customer_id'),
                'old' => (float) $open()->whereDate('sold_at', '<', $batas)->sum('balance'),
                'old_count' => $open()->whereDate('sold_at', '<', $batas)->count(),
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return ReceivablesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function store(CustomerPaymentRequest $request, Sale $sale, PaymentService $payments, CashSessionService $sessions): RedirectResponse
    {
        $data = $request->validated();
        $payment = $payments->terimaPenjualan(
            $sale,
            Uang::sen($data['amount']),
            PaymentMethod::from($data['method']),
            filled($data['note'] ?? null) ? $data['note'] : 'Pelunasan piutang',
            $request->user(),
            $data['paid_at'],
            $sessions->current($request->user()),
        );

        return back()->with('success', "Pelunasan {$payment->number} untuk penjualan {$sale->number} tercatat.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'pelanggan' => ['nullable', 'integer', 'exists:customers,id'],
            'umur' => ['nullable', Rule::in(array_keys(ReceivablesExport::AGE_FILTERS))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}
