<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\InvoiceType;
use App\Exports\Accounting\InvoicesExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\InvoiceRequest;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SettlementAllocation;
use App\Models\Supplier;
use App\Services\Receivables\InvoiceService;
use App\Services\Tax\TaxRateService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Faktur penjualan (sales-invoices.*, izin pos.invoices.*) dan tagihan pembelian
 * (purchase-bills.*, izin purchasing.bills.*) manual. Jenis dari nama route.
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): View
    {
        $type = $this->type($request);

        return view('accounting.invoices.index', [
            'type' => $type,
            'invoices' => InvoicesExport::fromRequest($request, $this->filter($request) + ['type' => $type->value])->paginate(20),
            'parties' => $this->parties($type),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return InvoicesExport::fromRequest($request, $this->filter($request) + ['type' => $this->type($request)->value])->download($format);
    }

    public function create(Request $request): View
    {
        $type = $this->type($request);
        $invoice = new Invoice(['type' => $type, 'invoice_date' => now()]);
        $invoice->forceFill(['tax_rate' => setting('tax.enabled') ? (string) setting('tax.rate', '0') : '0']);
        if ($type === InvoiceType::Sales) {
            $invoice->customer_id = $request->integer('pelanggan') ?: null;
        } else {
            $invoice->supplier_id = $request->integer('supplier') ?: null;
        }

        return view('accounting.invoices.form', $this->formData($invoice));
    }

    public function store(InvoiceRequest $request): RedirectResponse
    {
        $type = $request->type();
        $invoice = $this->invoices->saveDraft($type, $request->validated(), $request->user());
        if ($request->input('aksi', 'post') === 'post') {
            $invoice = $this->invoices->post($invoice, $request->user());
        }

        return redirect()->route($type->routePrefix().'.show', $invoice)->with('success', $this->message($invoice));
    }

    public function show(Request $request, Invoice $invoice): View
    {
        $this->ensureType($request, $invoice);

        return view('accounting.invoices.show', [
            'invoice' => $invoice->load(['lines.account', 'lines.costCenter', 'customer', 'supplier', 'creator', 'postedBy', 'cancelledBy', 'payments.settlement', 'openingBalance']),
            'notes' => SettlementAllocation::query()->where('document_type', 'invoice')->where('document_id', $invoice->id)->whereNull('payment_id')
                ->with('settlement')->get()->filter(fn ($a) => $a->settlement?->isPosted()),
        ]);
    }

    public function edit(Request $request, Invoice $invoice): View|RedirectResponse
    {
        $this->ensureType($request, $invoice);
        if (! $invoice->isEditable()) {
            return redirect()->route($invoice->type->routePrefix().'.show', $invoice)->with('error', 'Hanya draf yang bisa diubah.');
        }

        return view('accounting.invoices.form', $this->formData($invoice->load('lines')));
    }

    public function update(InvoiceRequest $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureType($request, $invoice);
        $invoice = $this->invoices->saveDraft($invoice->type, $request->validated(), $request->user(), $invoice);
        if ($request->input('aksi', 'post') === 'post') {
            $invoice = $this->invoices->post($invoice, $request->user());
        }

        return redirect()->route($invoice->type->routePrefix().'.show', $invoice)->with('success', $this->message($invoice));
    }

    public function post(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureType($request, $invoice);
        $invoice = $this->invoices->post($invoice, $request->user());

        return redirect()->route($invoice->type->routePrefix().'.show', $invoice)->with('success', $this->message($invoice));
    }

    /** Draf: izin create. Faktur posted (jurnal dibalik): izin void. */
    public function cancel(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureType($request, $invoice);
        abort_unless($request->user()->can($invoice->type->permission($invoice->isPosted() ? 'void' : 'create')), 403);
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $this->invoices->cancel($invoice, $request->user(), $request->input('alasan'));

        return redirect()->route($invoice->type->routePrefix().'.show', $invoice)->with('success', "{$invoice->number} dibatalkan.");
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->ensureType($request, $invoice);
        $this->invoices->deleteDraft($invoice);

        return redirect()->route($invoice->type->routePrefix().'.index')->with('success', "Draf {$invoice->number} dihapus.");
    }

    private function message(Invoice $invoice): string
    {
        return $invoice->documentStatus() === DocumentStatus::Posted
            ? "{$invoice->type->label()} {$invoice->number} diposting. Sisa tagihan ".Format::rupiah($invoice->balance).'.'
            : "Draf {$invoice->number} tersimpan.";
    }

    private function type(Request $request): InvoiceType
    {
        return Str::startsWith((string) $request->route()?->getName(), 'sales-invoices.') ? InvoiceType::Sales : InvoiceType::Purchase;
    }

    private function ensureType(Request $request, Invoice $invoice): void
    {
        abort_unless($invoice->type === $this->type($request), 404);
    }

    /** @return Collection<int|string, string> */
    private function parties(InvoiceType $type)
    {
        return $type === InvoiceType::Sales
            ? Customer::query()->where('is_default', false)->orderBy('name')->pluck('name', 'id')
            : Supplier::query()->orderBy('name')->pluck('name', 'id');
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'pihak' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['belum_lunas', 'lunas', 'draft', 'posted', 'cancelled'])],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Invoice $invoice): array
    {
        $sales = $invoice->type === InvoiceType::Sales;

        return [
            'invoice' => $invoice,
            'parties' => $this->parties($invoice->type),
            'accounts' => Account::query()->postable()->whereIn('type', $sales ? ['revenue', 'liability', 'equity'] : ['expense', 'cogs', 'asset', 'liability'])->ordered()->get(['id', 'code', 'name', 'type']),
            'defaultAccount' => $sales ? Account::query()->where('code', '4-2000')->value('id') : null,
            'costCenters' => CostCenter::query()->active()->ordered()->get(['id', 'code', 'name']),
            'withholdings' => $sales ? collect() : app(TaxRateService::class)->options($invoice->invoice_date),
        ];
    }
}
