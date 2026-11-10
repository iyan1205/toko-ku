<?php

namespace App\Exports\Inventory;

use App\Models\Category;

class CategoriesExport extends MasterDataExport
{
    public function documentTitle(): string
    {
        return 'Daftar Kategori';
    }

    protected function model(): string
    {
        return Category::class;
    }
}
