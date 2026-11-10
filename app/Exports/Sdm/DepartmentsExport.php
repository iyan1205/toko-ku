<?php

namespace App\Exports\Sdm;

use App\Models\Department;

class DepartmentsExport extends OrganizationUnitsExport
{
    public function documentTitle(): string
    {
        return 'Daftar Departemen';
    }

    protected function model(): string
    {
        return Department::class;
    }
}
