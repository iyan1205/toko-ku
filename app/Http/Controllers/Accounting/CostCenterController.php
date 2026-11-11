<?php

namespace App\Http\Controllers\Accounting;

use App\Exports\Accounting\CostCentersExport;
use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CostCenterRequest;
use App\Models\CostCenter;
use App\Models\Department;
use App\Services\Accounting\CostCenterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Cost center / departemen (dimensi laporan). Izin accounting.cost-centers.*. */
class CostCenterController extends Controller
{
    public function __construct(private readonly CostCenterService $costCenters) {}

    public function index(Request $request): View
    {
        return view('accounting.cost-centers.index', [
            'costCenters' => CostCentersExport::fromRequest($request, $this->filter($request))->paginate(20),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);

        return CostCentersExport::fromRequest($request, $this->filter($request))->download($format);
    }

    public function create(): View
    {
        return view('accounting.cost-centers.form', $this->formData(new CostCenter(['is_active' => true])));
    }

    public function store(CostCenterRequest $request): RedirectResponse
    {
        $costCenter = $this->costCenters->create($request->validated());

        return redirect()->route('cost-centers.index')->with('success', "Cost center {$costCenter->label()} ditambahkan.");
    }

    public function edit(CostCenter $costCenter): View
    {
        return view('accounting.cost-centers.form', $this->formData($costCenter));
    }

    public function update(CostCenterRequest $request, CostCenter $costCenter): RedirectResponse
    {
        $this->costCenters->update($costCenter, $request->validated());

        return redirect()->route('cost-centers.index')->with('success', "Cost center {$costCenter->label()} diperbarui.");
    }

    public function destroy(CostCenter $costCenter): RedirectResponse
    {
        $this->costCenters->delete($costCenter);

        return redirect()->route('cost-centers.index')->with('success', "Cost center {$costCenter->label()} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }

    /** @return array<string, mixed> */
    private function formData(CostCenter $costCenter): array
    {
        return [
            'costCenter' => $costCenter,
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id'),
        ];
    }
}
