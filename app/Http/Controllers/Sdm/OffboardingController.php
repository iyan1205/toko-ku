<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\TerminationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sdm\OffboardingRequest;
use App\Models\Employee;
use App\Models\EmployeeOffboarding;
use App\Models\EmployeeOffboardingItem;
use App\Services\Sdm\OffboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Offboarding: daftar, mulai dari halaman karyawan, checklist, selesai (approve), batal. Izin sdm.offboarding.*. */
class OffboardingController extends Controller
{
    public function __construct(private readonly OffboardingService $offboardings) {}

    public function index(Request $request): View
    {
        $f = $request->validate(['cari' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(array_keys(EmployeeOffboarding::STATUSES))]]);

        return view('sdm.offboardings.index', [
            'offboardings' => EmployeeOffboarding::query()->filter($f)
                ->with(['employee' => fn ($q) => $q->withTrashed()->select(['id', 'code', 'name', 'position_id', 'is_active']), 'employee.position:id,name'])
                ->withCount(['items', 'items as done_count' => fn ($q) => $q->where('is_done', true)])
                ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 ELSE 1 END")->latest('last_working_date')->latest('id')
                ->paginate(15)->withQueryString(),
            'statuses' => EmployeeOffboarding::STATUSES,
        ]);
    }

    public function store(OffboardingRequest $request, Employee $employee): RedirectResponse
    {
        $offboarding = $this->offboardings->start($employee, $request->validated(), $request->user());

        return redirect()->route('offboardings.show', $offboarding)->with('success', "Offboarding {$employee->name} dimulai. Selesaikan checklist pengembaliannya.");
    }

    public function show(EmployeeOffboarding $offboarding): View
    {
        $offboarding->load(['employee' => fn ($q) => $q->withTrashed(), 'employee.user:id,name,email,is_active', 'employee.position:id,name', 'items.doneBy:id,name', 'items.fixedAsset:id,code,name', 'creator:id,name', 'completedBy:id,name', 'cancelledBy:id,name']);

        return view('sdm.offboardings.show', ['offboarding' => $offboarding, 'types' => TerminationType::cases()]);
    }

    public function addItem(Request $request, EmployeeOffboarding $offboarding): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:150']], [], ['label' => 'nama barang/tugas']);
        $this->offboardings->addItem($offboarding, $data['label']);

        return back()->with('success', 'Checklist ditambahkan.');
    }

    public function toggleItem(Request $request, EmployeeOffboarding $offboarding, EmployeeOffboardingItem $item): RedirectResponse
    {
        abort_unless($item->employee_offboarding_id === $offboarding->id, 404);
        $data = $request->validate(['done' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->offboardings->toggle($item, (bool) $data['done'], $request->user(), $data['note'] ?? null);

        return back();
    }

    public function complete(Request $request, EmployeeOffboarding $offboarding): RedirectResponse
    {
        $this->offboardings->complete($offboarding, $request->user());
        $offboarding->refresh();

        return redirect()->route('offboardings.show', $offboarding)->with('success', $offboarding->deactivated_at
            ? 'Offboarding selesai. Karyawan'.($offboarding->deactivate_user ? ' dan akun loginnya' : '').' sudah dinonaktifkan.'
            : 'Offboarding selesai. Karyawan dinonaktifkan otomatis setelah hari terakhir kerjanya.');
    }

    public function cancel(Request $request, EmployeeOffboarding $offboarding): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [], ['alasan' => 'alasan']);
        $this->offboardings->cancel($offboarding, $request->user(), $request->input('alasan'));

        return redirect()->route('offboardings.show', $offboarding)->with('success', 'Offboarding dibatalkan.');
    }
}
