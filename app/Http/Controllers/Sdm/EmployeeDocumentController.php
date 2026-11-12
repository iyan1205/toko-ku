<?php

namespace App\Http\Controllers\Sdm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\EmployeeDocumentRequest;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\Sdm\EmployeeDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Dokumen karyawan di halaman detail (izin sdm.employees.update). Berkasnya dibuka lewat route lampiran. */
class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly EmployeeDocumentService $documents) {}

    public function store(EmployeeDocumentRequest $request, Employee $employee): RedirectResponse
    {
        $document = $this->documents->add($employee, $request->validated(), $request->user());

        return redirect()->route('employees.show', [$employee, 'tab' => 'dokumen'])->with('success', "Dokumen {$document->name} ditambahkan.");
    }

    public function destroy(Request $request, Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        abort_unless($document->employee_id === $employee->id, 404);
        $this->documents->delete($document, $request->user());

        return redirect()->route('employees.show', [$employee, 'tab' => 'dokumen'])->with('success', "Dokumen {$document->name} dihapus.");
    }
}
