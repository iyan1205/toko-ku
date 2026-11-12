<?php

namespace App\Http\Controllers\Sdm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\EmployeeContractRequest;
use App\Http\Requests\Sdm\EmployeeMovementRequest;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeMovement;
use App\Services\Sdm\EmployeeContractService;
use App\Services\Sdm\EmployeeMovementService;
use App\Support\Format;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Riwayat kontrak dan mutasi/promosi di halaman detail karyawan (izin sdm.employees.update). */
class EmployeeHistoryController extends Controller
{
    public function storeContract(EmployeeContractRequest $request, Employee $employee, EmployeeContractService $contracts): RedirectResponse
    {
        $contract = $contracts->add($employee, $request->validated(), $request->user());

        return redirect()->route('employees.show', [$employee, 'tab' => 'kontrak'])
            ->with('success', "Kontrak {$contract->employment_type->label()} {$contract->periodLabel()} ditambahkan.");
    }

    public function destroyContract(Request $request, Employee $employee, EmployeeContract $contract, EmployeeContractService $contracts): RedirectResponse
    {
        abort_unless($contract->employee_id === $employee->id, 404);
        $contracts->delete($contract, $request->user());

        return redirect()->route('employees.show', [$employee, 'tab' => 'kontrak'])->with('success', 'Kontrak dihapus.');
    }

    public function storeMovement(EmployeeMovementRequest $request, Employee $employee, EmployeeMovementService $movements): RedirectResponse
    {
        $movement = $movements->record($employee, $request->validated(), $request->user());
        $when = $movement->isApplied() ? 'sudah diterapkan' : 'akan diterapkan '.Format::tanggal($movement->effective_date);

        return redirect()->route('employees.show', [$employee, 'tab' => 'mutasi'])->with('success', "{$movement->type->label()} dicatat dan {$when}.");
    }

    public function destroyMovement(Request $request, Employee $employee, EmployeeMovement $movement, EmployeeMovementService $movements): RedirectResponse
    {
        abort_unless($movement->employee_id === $employee->id, 404);
        $movements->cancel($movement, $request->user());

        return redirect()->route('employees.show', [$employee, 'tab' => 'mutasi'])->with('success', "{$movement->type->label()} dibatalkan.");
    }
}
