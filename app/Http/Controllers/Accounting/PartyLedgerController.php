<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Receivables\PartyLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Laporan piutang (receivable-reports.*) dan hutang (payable-reports.*): umur per pihak,
 * kartu per pihak, dan jadwal pembayaran hutang.
 */
class PartyLedgerController extends Controller
{
    public function __construct(private readonly PartyLedgerService $ledger) {}

    public function aging(Request $request): View
    {
        $receivable = $this->receivable($request);
        $basis = $request->validate(['dasar' => ['nullable', Rule::in(array_keys(PartyLedgerService::BUCKETS))]])['dasar'] ?? 'faktur';

        return view('accounting.party-ledger.aging', [
            'receivable' => $receivable,
            'basis' => $basis,
            'aging' => $this->ledger->aging($receivable, $basis, CarbonImmutable::today()),
        ]);
    }

    public function card(Request $request): View
    {
        $receivable = $this->receivable($request);
        $key = $receivable ? 'pelanggan' : 'supplier';
        $data = $request->validate([
            $key => ['nullable', 'integer'],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
        $from = $data['dari'] ?? now()->startOfYear()->toDateString();
        $to = $data['sampai'] ?? now()->toDateString();
        $party = ! empty($data[$key]) ? ($receivable ? Customer::withTrashed()->find($data[$key]) : Supplier::withTrashed()->find($data[$key])) : null;

        return view('accounting.party-ledger.card', [
            'receivable' => $receivable,
            'party' => $party,
            'parties' => $receivable ? Customer::query()->where('is_default', false)->orderBy('name')->pluck('name', 'id') : Supplier::query()->orderBy('name')->pluck('name', 'id'),
            'from' => $from,
            'to' => $to,
            'card' => $party ? $this->ledger->card($receivable, $party->id, $from, $to) : null,
            'open' => $party ? $this->ledger->openItems($receivable, $party->id) : collect(),
        ]);
    }

    public function schedule(): View
    {
        return view('accounting.party-ledger.schedule', [
            'groups' => $this->ledger->schedule(CarbonImmutable::today()),
        ]);
    }

    private function receivable(Request $request): bool
    {
        return Str::startsWith((string) $request->route()?->getName(), 'receivable-reports.');
    }
}
