<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\EmploymentType;
use App\Exports\ListExport;
use App\Exports\Sdm\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\EmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\Sdm\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Karyawan (SDM). Izin sdm.employees.* dipasang di routes/modules/sdm.php. */
class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): View
    {
        return view('sdm.employees.index', [
            'employees' => EmployeesExport::fromRequest($request, $this->filter($request))->paginate(15),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return EmployeesExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(): View
    {
        return view('sdm.employees.form', $this->formData(new Employee(['is_active' => true, 'employment_type' => EmploymentType::Permanent, 'join_date' => now()])));
    }

    public function store(EmployeeRequest $request): RedirectResponse
    {
        $employee = $this->employees->create($request->validated());

        return redirect()->route('employees.index')->with('success', "Karyawan {$employee->name} ({$employee->code}) ditambahkan.");
    }

    public function edit(Employee $employee): View
    {
        return view('sdm.employees.form', $this->formData($employee));
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->update($employee, $request->validated());

        return redirect()->route('employees.index')->with('success', "Data karyawan {$employee->name} diperbarui.");
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $this->employees->delete($employee);

        return redirect()->route('employees.index')->with('success', "Karyawan {$employee->name} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'departemen' => ['nullable', 'integer', 'exists:departments,id'],
            'jabatan' => ['nullable', 'integer', 'exists:positions,id'],
            'jenis' => ['nullable', Rule::enum(EmploymentType::class)],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Employee $employee): array
    {
        return [
            'employee' => $employee,
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
            // Akun yang belum tertaut ke karyawan lain, ditambah akun karyawan ini sendiri.
            'users' => User::query()
                ->where(fn ($q) => $q->whereDoesntHave('employee')->when($employee->user_id, fn ($w, $id) => $w->orWhere('id', $id)))
                ->orderBy('name')->get(['id', 'name', 'email']),
        ];
    }
}
