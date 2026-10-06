<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    /** `code` dibuat otomatis oleh SupplierService, tidak diisi dari formulir. */
    protected $fillable = ['code', 'name', 'contact_name', 'phone', 'email', 'address', 'npwp', 'note'];

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public function scopeCari(Builder $query, ?string $kata): Builder
    {
        if ($kata === null || trim($kata) === '') {
            return $query;
        }

        $kata = '%' . trim($kata) . '%';

        return $query->where(function (Builder $q) use ($kata) {
            $q->where('name', 'like', $kata)
                ->orWhere('code', 'like', $kata)
                ->orWhere('contact_name', 'like', $kata)
                ->orWhere('phone', 'like', $kata);
        });
    }
}
