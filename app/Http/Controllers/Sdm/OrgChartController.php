<?php

namespace App\Http\Controllers\Sdm;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bagan organisasi dari relasi atasan langsung (employees.supervisor_id), karyawan aktif saja.
 * Karyawan yang atasannya tidak ikut tersaring (beda cabang/departemen) tampil sebagai puncak.
 * Izin sdm.organization.view.
 */
class OrgChartController extends Controller
{
    public function index(Request $request): View
    {
        $f = $request->validate([
            'cabang' => ['nullable', 'integer', 'exists:branches,id'],
            'departemen' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $employees = Employee::query()->where('is_active', true)
            ->when($f['cabang'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($f['departemen'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->with(['position:id,name', 'department:id,name', 'branch:id,name'])
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'supervisor_id', 'position_id', 'department_id', 'branch_id', 'photo_path']);

        $byParent = $employees->groupBy(fn (Employee $e) => $employees->contains('id', $e->supervisor_id) ? (int) $e->supervisor_id : 0);
        $seen = [];
        $build = function (int $parent) use (&$build, &$seen, $byParent): array {
            $nodes = [];
            foreach ($byParent->get($parent, collect()) as $e) {
                if (isset($seen[$e->id])) {
                    continue;
                }
                $seen[$e->id] = true;
                $nodes[] = ['employee' => $e, 'children' => $build($e->id)];
            }

            return $nodes;
        };

        return view('sdm.org-chart.index', [
            'tree' => $build(0),
            'total' => $employees->count(),
            'withoutSupervisor' => $employees->whereNull('supervisor_id')->count(),
            'filters' => $f,
            'branches' => Branch::query()->ordered()->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
