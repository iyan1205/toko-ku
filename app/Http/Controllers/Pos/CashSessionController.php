<?php

namespace App\Http\Controllers\Pos;

use App\Exports\ListExport;
use App\Exports\Pos\CashSessionsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CloseCashSessionRequest;
use App\Http\Requests\Pos\OpenCashSessionRequest;
use App\Http\Requests\Pos\VerifyCashSessionRequest;
use App\Models\CashSession;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pos\CashSessionService;
use App\Support\Format;
use App\Support\Uang;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi kasir: daftar, buka (dari layar Kasir atau halaman ini), detail + rekap setoran, tutup, verifikasi.
 * Hak per sesi dicek CashSessionPolicy: kasir hanya sesinya sendiri, pos.sessions.approve semua sesi.
 */
class CashSessionController extends Controller
{
    public function __construct(private readonly CashSessionService $sessions) {}

    public function index(Request $request): View
    {
        $filter = $this->filter($request);
        $list = CashSessionsExport::fromRequest($request, $filter);
        $user = $request->user();

        return view('pos.sessions.index', [
            'list' => $list,
            'sessions' => $list->paginate(15),
            'current' => $this->sessions->current($user),
            'viewAll' => Gate::allows('viewAll', CashSession::class),
            'cashiers' => Gate::allows('viewAll', CashSession::class)
                ? User::withTrashed()->whereIn('id', CashSession::query()->select('user_id')->distinct())->orderBy('name')->get(['id', 'name'])
                : collect(),
            'warehouses' => Warehouse::query()->active()->inCurrentBranch()->ordered()->get(['id', 'name']),
            'defaultWarehouse' => $this->sessions->branchWarehouse(),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return CashSessionsExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function store(OpenCashSessionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $session = $this->sessions->open(
            $request->user(),
            Warehouse::findOrFail($data['warehouse_id']),
            Uang::sen($data['opening_cash']),
            $data['opening_note'] ?? null,
        );

        $pesan = "Sesi {$session->number} dibuka di {$session->warehouse->name} dengan modal awal ".Format::rupiah($session->opening_cash).'.';

        return $request->input('_from') === 'pos'
            ? redirect()->route('pos.index')->with('success', $pesan)
            : redirect()->route('pos.sessions.show', $session)->with('success', $pesan);
    }

    public function show(CashSession $cashSession): View
    {
        Gate::authorize('view', $cashSession);

        return view('pos.sessions.show', [
            'session' => $cashSession->load(['user', 'warehouse', 'branch', 'closedBy', 'verifiedBy']),
            'summary' => $this->sessions->summary($cashSession),
            'sales' => $cashSession->sales()->with('customer:id,name')->orderByDesc('sold_at')->orderByDesc('id')->limit(50)->get(),
        ]);
    }

    public function close(CloseCashSessionRequest $request, CashSession $cashSession): RedirectResponse
    {
        Gate::authorize('close', $cashSession);

        $data = $request->validated();
        $session = $this->sessions->close($cashSession, Uang::sen($data['closing_cash']), $data['closing_note'] ?? null, $request->user());

        $selisih = Uang::sen($session->cash_difference);
        $pesan = "Sesi {$session->number} ditutup. ".match (true) {
            $selisih === 0 => 'Uang fisik cocok dengan catatan.',
            $selisih > 0 => 'Uang fisik lebih '.Format::rupiah($session->cash_difference).'.',
            default => 'Uang fisik kurang '.Format::rupiah(abs((float) $session->cash_difference)).'.',
        };

        return redirect()->route('pos.sessions.show', $session)->with('success', $pesan);
    }

    public function verify(VerifyCashSessionRequest $request, CashSession $cashSession): RedirectResponse
    {
        Gate::authorize('verify', $cashSession);

        $this->sessions->verify($cashSession, $request->validated('verification_note'), $request->user());

        return redirect()->route('pos.sessions.show', $cashSession)->with('success', "Setoran sesi {$cashSession->number} diverifikasi.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'kasir' => ['nullable', 'integer'],
            'gudang' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', Rule::in(array_keys(CashSessionsExport::STATUSES))],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        if (Gate::denies('viewAll', CashSession::class)) {
            $filter['kasir'] = $request->user()->id;
        }

        return $filter;
    }
}
