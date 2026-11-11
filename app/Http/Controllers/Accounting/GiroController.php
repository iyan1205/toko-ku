<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\GiroStatus;
use App\Enums\SettlementType;
use App\Http\Controllers\Controller;
use App\Models\CashBankAccount;
use App\Models\Settlement;
use App\Services\Receivables\SettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Giro/cek mundur dari pelunasan: daftar, cairkan ke rekening, atau tolak (pelunasan dibatalkan).
 * Izin mengikuti sisinya: piutang (pos.receivables.*) atau hutang (purchasing.payables.*).
 */
class GiroController extends Controller
{
    public function __construct(private readonly SettlementService $settlements) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(GiroStatus::class)],
            'arah' => ['nullable', Rule::in(['masuk', 'keluar'])],
        ]);
        // Giro masuk (dari pelanggan) butuh izin piutang, giro keluar (ke supplier) izin hutang.
        $arah = $filters['arah'] ?? null;
        $types = array_values(array_filter([
            $arah !== 'keluar' && $user->can('pos.receivables.view') ? SettlementType::Receipt : null,
            $arah !== 'masuk' && $user->can('purchasing.payables.view') ? SettlementType::Payment : null,
        ]));

        return view('accounting.cash-bank.giros.index', [
            'giros' => Settlement::query()->where('method', 'giro')->ofTypes(...$types)
                ->where('giro_status', $filters['status'] ?? GiroStatus::Pending->value)
                ->with(['customer:id,name', 'supplier:id,name', 'giroCashBankAccount:id,name'])
                ->orderBy('giro_due_date')->orderBy('id')->paginate(20)->withQueryString(),
            'status' => GiroStatus::from($filters['status'] ?? GiroStatus::Pending->value),
            'rekening' => CashBankAccount::query()->available()->active()->ordered()->get(['id', 'name', 'kind']),
        ]);
    }

    public function clear(Request $request, Settlement $settlement): RedirectResponse
    {
        abort_unless($settlement->isGiro() && $request->user()->can($settlement->type->permission('create')), 403);
        $data = $request->validate([
            'cash_bank_account_id' => ['required', 'integer'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
        ], [], ['cash_bank_account_id' => 'rekening', 'tanggal' => 'tanggal cair']);

        $rekening = CashBankAccount::query()->available()->findOrFail($data['cash_bank_account_id']);
        $this->settlements->clearGiro($settlement, $rekening, $data['tanggal'], $request->user());

        return back()->with('success', "Giro {$settlement->giro_number} dicatat cair ke {$rekening->name}.");
    }

    public function bounce(Request $request, Settlement $settlement): RedirectResponse
    {
        abort_unless($settlement->isGiro() && $request->user()->can($settlement->type->permission('void')), 403);
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [], ['alasan' => 'alasan']);
        $this->settlements->bounceGiro($settlement, $request->user(), $request->input('alasan'));

        return back()->with('success', "Giro {$settlement->giro_number} ditolak. Pelunasan {$settlement->number} dibatalkan dan sisa fakturnya kembali.");
    }
}
