<?php

namespace App\Http\Controllers\Sdm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\OvertimeRequestRequest;
use App\Http\Requests\Sdm\ReimbursementRequestRequest;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\ReimbursementRequest;
use App\Models\Scopes\BranchScope;
use App\Services\Approval\ApprovalService;
use App\Services\Sdm\EmployeeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Pengajuan lembur dan reimbursement milik karyawan sendiri (Layanan Mandiri). Izin sdm.overtime.create / sdm.reimbursements.create. */
class SelfServiceRequestController extends Controller
{
    public function __construct(private readonly EmployeeRequestService $requests) {}

    public function overtime(Request $request): View
    {
        if (! $employee = $request->user()->employee) {
            return view('sdm.ess.unlinked');
        }

        return view('sdm.ess.overtime', [
            'employee' => $employee,
            'items' => OvertimeRequest::withoutGlobalScope(BranchScope::class)->where('employee_id', $employee->id)->latest('overtime_date')->latest('id')->paginate(10),
            'needsFlow' => app(ApprovalService::class)->requiresApproval(new OvertimeRequest(['duration_minutes' => 60])),
        ]);
    }

    public function storeOvertime(OvertimeRequestRequest $request): RedirectResponse
    {
        $overtime = $this->requests->submitOvertime($this->employeeOrFail($request), $request->validated(), $request->user());

        return redirect()->route('ess.overtime')->with('success', "Lembur {$overtime->number} ({$overtime->durationLabel()}) diajukan.");
    }

    public function cancelOvertime(Request $request, int $id): RedirectResponse
    {
        $overtime = OvertimeRequest::withoutGlobalScope(BranchScope::class)->where('employee_id', $this->employeeOrFail($request)->id)->findOrFail($id);
        $this->requests->cancel($overtime, $request->user());

        return redirect()->route('ess.overtime')->with('success', "Lembur {$overtime->number} dibatalkan.");
    }

    public function reimbursements(Request $request): View
    {
        if (! $employee = $request->user()->employee) {
            return view('sdm.ess.unlinked');
        }

        return view('sdm.ess.reimbursements', [
            'employee' => $employee,
            'items' => ReimbursementRequest::withoutGlobalScope(BranchScope::class)->where('employee_id', $employee->id)->with('attachments')->latest('expense_date')->latest('id')->paginate(10),
        ]);
    }

    public function storeReimbursement(ReimbursementRequestRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $reimbursement = $this->requests->submitReimbursement($this->employeeOrFail($request), collect($data)->except('receipts')->all(), $data['receipts'], $request->user());

        return redirect()->route('ess.reimbursements')->with('success', "Reimbursement {$reimbursement->number} diajukan.");
    }

    public function cancelReimbursement(Request $request, int $id): RedirectResponse
    {
        $reimbursement = ReimbursementRequest::withoutGlobalScope(BranchScope::class)->where('employee_id', $this->employeeOrFail($request)->id)->findOrFail($id);
        $this->requests->cancel($reimbursement, $request->user());

        return redirect()->route('ess.reimbursements')->with('success', "Reimbursement {$reimbursement->number} dibatalkan.");
    }

    private function employeeOrFail(Request $request): Employee
    {
        return $request->user()->employee ?? abort(403, 'Akun Anda belum ditautkan ke data karyawan.');
    }
}
