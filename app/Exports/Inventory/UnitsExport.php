<?php

namespace App\Exports\Inventory;

use App\Models\Unit;

class UnitsExport extends MasterDataExport
{
    public function documentTitle(): string
    {
        return 'Daftar Satuan';
    }

    protected function model(): string
    {
        return Unit::class;
    }
}
