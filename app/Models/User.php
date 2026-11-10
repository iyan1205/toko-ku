<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, RecordsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'all_branches' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin->value);
    }

    public function scopeAktif(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCari(Builder $query, ?string $kata): Builder
    {
        if ($kata === null || trim($kata) === '') {
            return $query;
        }

        $kata = '%'.trim($kata).'%';

        return $query->where(function (Builder $q) use ($kata) {
            $q->where('name', 'like', $kata)
                ->orWhere('email', 'like', $kata)
                ->orWhere('phone', 'like', $kata);
        });
    }

    /** Nama peran utama (satu pengguna = satu peran pada aplikasi ini). */
    public function namaPeran(): string
    {
        return $this->roles->pluck('name')->first() ?? '-';
    }

    protected function activityLogName(): string
    {
        return 'pengguna';
    }

    protected function activityLogLabel(): string
    {
        return 'Pengguna';
    }

    /** @return list<string> */
    protected function activityLogAttributes(): array
    {
        return ['name', 'email', 'phone', 'is_active'];
    }

    /** Cabang yang boleh diakses bila all_branches = false (lihat App\Support\CurrentBranch). */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    /** Data karyawan yang tertaut ke akun ini (SDM), bila ada. */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }
}
