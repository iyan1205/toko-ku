<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unit extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['name', 'description'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function dipakaiProduk(): int
    {
        return $this->products()->withTrashed()->count();
    }

    protected function activityLogName(): string
    {
        return 'inventori';
    }

    protected function activityLogLabel(): string
    {
        return 'Satuan';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return $this->getFillable();
    }
}
