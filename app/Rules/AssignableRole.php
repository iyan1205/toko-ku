<?php

namespace App\Rules;

use App\Enums\RoleName;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Spatie\Permission\Models\Role;

/**
 * Peran yang boleh diberikan oleh pengguna yang sedang login: harus ada,
 * dan peran Super Admin hanya boleh diberikan oleh Super Admin.
 */
class AssignableRole implements ValidationRule
{
    public function __construct(private readonly ?User $actor) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! Role::query()->where('guard_name', 'web')->where('name', $value)->exists()) {
            $fail('Peran yang dipilih tidak tersedia.');

            return;
        }

        if ($value === RoleName::SuperAdmin->value && ! $this->actor?->isSuperAdmin()) {
            $fail('Hanya Super Admin yang dapat memberikan peran Super Admin.');
        }
    }
}
