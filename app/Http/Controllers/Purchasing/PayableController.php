<?php

namespace App\Http\Controllers\Purchasing;

use App\Enums\PaymentMethod;
use App\Exports\ListExport;
use App\Exports\Purchasing\PayablesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierPaymentRequest;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PaymentService;
use App\Support\Uang;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hutang supplier: faktur yang masih punya sisa hutang, dan pembayarannya.
 * Pembayaran memakai PaymentService::bayarPembelian() (mengunci faktur, menolak lebih bayar).
 */
class PayableController extends Controller
{
    public function index(Request $request): View
    {
        $list = PayablesExport::fromRequest($request, $this->filter($request));
        $today = CarbonImmutable::today();
        $open = fn () => Purchase::query()->outstanding();

        return view('purchasing.payables.index', [
            'list' => $list,
            'payables' => $list->paginate(15),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'methods' => PaymentMethod::untukPembelian(),
            'summary' => [
                'total' => (float) $open()->sum('balance'),
                'count' => $open()->count(),
                'overdue' => (float) $open()->whereDate('due_date', '<=', $today)->sum('balance'),
                'overdue_count' => $open()->whereDate('due_date', '<=', $today)->count(),
                'week' => (float) $open()->whereDate('due_date', '>', $today)->whereDate('due_date', '<=', $today->addDays(7))->sum('balance'),
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return PayablesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function store(SupplierPaymentRequest $request, Purchase $purchase, PaymentService $payments): RedirectResponse
    {
        $data = $request->validated();
        $payment = $payments->bayarPembelian(
            $purchase,
            Uang::sen($data['amount']),
            PaymentMethod::from($data['method']),
            $data['note'] ?? null,
            $request->user(),
            $data['paid_at'],
        );

        return back()->with('success', "Pembayaran {$payment->number} untuk faktur {$purchase->number} tercatat.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'supplier' => ['nullable', 'integer', 'exists:suppliers,id'],
            'jatuh_tempo' => ['nullable', Rule::in(array_keys(PayablesExport::DUE_FILTERS))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}
