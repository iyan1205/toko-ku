<?php

namespace App\Exports\Sdm;

use App\Models\Position;

class PositionsExport extends OrganizationUnitsExport
{
    public function documentTitle(): string
    {
        return 'Daftar Jabatan';
    }

    protected function model(): string
    {
        return Position::class;
    }
}
