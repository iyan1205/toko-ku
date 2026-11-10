<?php

namespace App\Http\Controllers\Approval;

use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ApprovalRequest;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Persetujuan Saya": kotak masuk dokumen yang menunggu persetujuan pengguna ini, pengajuannya
 * sendiri, dan riwayat keputusannya. Semua pengguna login boleh membuka; siapa yang boleh
 * menyetujui satu tahap diperiksa ApprovalService (peran/pengguna tahap, cabang, bukan pengaju).
 */
class ApprovalController extends Controller
{
    public const TABS = ['menunggu' => 'Menunggu saya', 'pengajuan' => 'Pengajuan saya', 'riwayat' => 'Keputusan saya'];

    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): View
    {
        $filter = $request->validate([
            'tab' => ['nullable', Rule::in(array_keys(self::TABS))],
            'status' => ['nullable', Rule::enum(ApprovalStatus::class)],
        ]);
        $tab = $filter['tab'] ?? 'menunggu';
        $user = $request->user();

        $items = match ($tab) {
            'menunggu' => $this->approvals->inbox($user)->with(['submitter:id,name', 'branch:id,name', 'approvable'])->oldest('submitted_at')->paginate(15),
            'pengajuan' => ApprovalRequest::query()->where('submitted_by', $user->getKey())
                ->when($filter['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
                ->with(['branch:id,name', 'approvable'])->latest('id')->paginate(15),
            'riwayat' => Approval::query()->where('user_id', $user->getKey())->whereIn('action', ['approved', 'rejected'])
                ->with(['request.submitter:id,name', 'request.approvable'])->latest('id')->paginate(15),
        };

        return view('approvals.index', [
            'tab' => $tab,
            'items' => $items->withQueryString(),
            'waitingCount' => $tab === 'menunggu' ? $items->total() : $this->approvals->inbox($user)->count(),
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $data = $request->validate(['catatan' => ['nullable', 'string', 'max:255']]);
        $hasil = $this->approvals->approve($approvalRequest, $request->user(), $data['catatan'] ?? null);

        return back()->with('success', $hasil->isPending()
            ? "{$hasil->title} disetujui. Diteruskan ke tahap {$hasil->current_step}: {$hasil->currentStep()['name']}."
            : "{$hasil->title} disetujui.");
    }

    public function reject(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:255']], [], ['alasan' => 'alasan']);
        $hasil = $this->approvals->reject($approvalRequest, $request->user(), $request->input('alasan'));

        return back()->with('success', "{$hasil->title} ditolak. Pengaju diberi tahu.");
    }

    /** Pengaju menarik pengajuannya; dokumen kembali menjadi draf. */
    public function cancel(Request $request, ApprovalRequest $approvalRequest): RedirectResponse
    {
        $hasil = $this->approvals->cancel($approvalRequest, $request->user());

        return back()->with('success', "Pengajuan {$hasil->title} ditarik. Dokumen bisa diubah lagi.");
    }
}
