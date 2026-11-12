<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\EmployeeMovementType;
use App\Enums\EmploymentType;
use App\Exports\ListExport;
use App\Exports\Sdm\EmployeesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\EmployeeRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\Sdm\EmployeeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Karyawan (SDM): daftar, formulir data lengkap, halaman detail, dan foto. Izin sdm.employees.* dipasang di routes/modules/sdm.php. */
class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeService $employees) {}

    public function index(Request $request): View
    {
        return view('sdm.employees.index', [
            'employees' => EmployeesExport::fromRequest($request, $this->filter($request))->paginate(15),
            'branches' => Branch::query()->ordered()->get(['id', 'name']),
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
        return view('sdm.employees.form', $this->formData(new Employee([
            'is_active' => true, 'employment_type' => EmploymentType::Permanent, 'join_date' => now(),
            'branch_id' => Branch::query()->where('is_default', true)->value('id'),
        ])));
    }

    public function store(EmployeeRequest $request): RedirectResponse
    {
        $employee = $this->employees->create($request->validated());

        return redirect()->route('employees.index')->with('success', "Karyawan {$employee->name} ({$employee->code}) ditambahkan.");
    }

    public function show(Employee $employee): View
    {
        $employee->load([
            'branch:id,name', 'department:id,name', 'position:id,name', 'supervisor:id,code,name', 'user:id,name,email,is_active',
            'subordinates' => fn ($q) => $q->orderBy('name')->select(['id', 'code', 'name', 'supervisor_id', 'position_id', 'is_active']),
            'subordinates.position:id,name', 'familyMembers', 'educations', 'experiences',
            'contracts.creator:id,name', 'movements.creator:id,name', 'documents.attachments', 'offboardings',
            'fixedAssets' => fn ($q) => $q->select(['id', 'code', 'name', 'employee_id', 'status', 'location']),
        ]);

        return view('sdm.employees.show', [
            'employee' => $employee,
            'branches' => Branch::query()->ordered()->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
            'supervisors' => Employee::query()->where('is_active', true)->whereKeyNot($employee->id)->orderBy('name')->get(['id', 'code', 'name']),
            'movementTypes' => collect(EmployeeMovementType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]),
        ]);
    }

    /** Foto karyawan dari disk lokal (tidak publik). */
    public function photo(Request $request, Employee $employee): Response
    {
        abort_unless($employee->photo_path && Storage::disk('local')->exists($employee->photo_path), 404);
        abort_unless($request->user()->can('sdm.employees.view') || $request->user()->employee?->is($employee), 403);

        return Storage::disk('local')->response($employee->photo_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function edit(Employee $employee): View
    {
        return view('sdm.employees.form', $this->formData($employee));
    }

    public function update(EmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $this->employees->update($employee, $request->validated());

        return redirect()->route('employees.show', $employee)->with('success', "Data karyawan {$employee->name} diperbarui.");
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
            'cabang' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(Employee $employee): array
    {
        $employee->loadMissing(['familyMembers', 'educations', 'experiences']);

        return [
            'employee' => $employee,
            'branches' => Branch::query()->ordered()->get(['id', 'name']),
            'supervisors' => Employee::query()->where('is_active', true)->when($employee->exists, fn ($q) => $q->whereKeyNot($employee->id))->orderBy('name')->get(['id', 'code', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'positions' => Position::orderBy('name')->get(['id', 'name']),
            // Akun yang belum tertaut ke karyawan lain, ditambah akun karyawan ini sendiri.
            'users' => User::query()
                ->where(fn ($q) => $q->whereDoesntHave('employee')->when($employee->user_id, fn ($w, $id) => $w->orWhere('id', $id)))
                ->orderBy('name')->get(['id', 'name', 'email']),
        ];
    }
}
