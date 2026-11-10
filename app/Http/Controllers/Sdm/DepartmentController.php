<?php

namespace App\Http\Controllers\Sdm;

use App\Exports\Sdm\DepartmentsExport;
use App\Http\Requests\Sdm\DepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;

class DepartmentController extends OrganizationUnitController
{
    public function store(DepartmentRequest $request): RedirectResponse
    {
        return $this->storeUnit($request, Department::class);
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        return $this->updateUnit($request, $department);
    }

    public function destroy(Department $department): RedirectResponse
    {
        return $this->destroyUnit($department);
    }

    protected function exportClass(): string
    {
        return DepartmentsExport::class;
    }

    protected function label(): string
    {
        return 'departemen';
    }

    protected function prefix(): string
    {
        return 'departments';
    }
}
