<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory, RecordsActivity;

    protected $fillable = ['name', 'description'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Termasuk produk yang sudah dihapus: foreign key tetap menahan baris tersebut. */
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
        return 'Kategori';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return $this->getFillable();
    }
}
