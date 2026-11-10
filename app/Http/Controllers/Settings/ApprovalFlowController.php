<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApprovalFlowRequest;
use App\Models\ApprovalFlow;
use App\Models\Branch;
use App\Models\User;
use App\Services\Approval\ApprovalFlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/** Pengaturan → Alur Persetujuan. Izin settings.approvals.* dipasang di routes/modules/settings.php. */
class ApprovalFlowController extends Controller
{
    public function __construct(private readonly ApprovalFlowService $flows) {}

    public function index(): View
    {
        return view('settings.approval-flows.index', [
            'flows' => ApprovalFlow::query()
                ->with(['branch:id,name', 'steps.role:id,name', 'steps.user:id,name,deleted_at'])
                ->withCount(['requests as pending_count' => fn ($q) => $q->where('status', 'pending')])
                ->orderBy('document_type')->orderBy('priority')->orderBy('id')
                ->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('settings.approval-flows.form', $this->formData(new ApprovalFlow([
            'document_type' => $request->string('dokumen')->toString() ?: array_key_first(config('approval.documents')),
            'priority' => 100,
            'is_active' => true,
            'conditions' => [['field' => 'total', 'operator' => '>', 'value' => '5000000']],
        ])));
    }

    public function store(ApprovalFlowRequest $request): RedirectResponse
    {
        $flow = $this->flows->save(null, $request->validated(), $request->user());

        return redirect()->route('settings.approval-flows.index')->with('success', "Alur persetujuan {$flow->name} disimpan.");
    }

    public function edit(ApprovalFlow $approvalFlow): View
    {
        return view('settings.approval-flows.form', $this->formData($approvalFlow->load('steps')));
    }

    public function update(ApprovalFlowRequest $request, ApprovalFlow $approvalFlow): RedirectResponse
    {
        $this->flows->save($approvalFlow, $request->validated(), $request->user());

        return redirect()->route('settings.approval-flows.index')->with('success', "Alur persetujuan {$approvalFlow->name} diperbarui. Pengajuan yang sedang berjalan tetap memakai tahap lama.");
    }

    public function destroy(ApprovalFlow $approvalFlow): RedirectResponse
    {
        $this->flows->delete($approvalFlow);

        return redirect()->route('settings.approval-flows.index')->with('success', "Alur persetujuan {$approvalFlow->name} dihapus. Pengajuan yang sedang berjalan tetap dilanjutkan.");
    }

    /** @return array<string, mixed> */
    private function formData(ApprovalFlow $flow): array
    {
        return [
            'flow' => $flow,
            'documents' => config('approval.documents'),
            'operators' => config('approval.operators'),
            'branches' => Branch::query()->ordered()->get(['id', 'name']),
            'roles' => Role::query()->where('guard_name', 'web')->orderBy('id')->get(['id', 'name']),
            'users' => User::query()->aktif()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
