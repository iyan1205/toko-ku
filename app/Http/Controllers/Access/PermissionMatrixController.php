<?php

namespace App\Http\Controllers\Access;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Services\Access\RoleService;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/**
 * Matriks hak akses satu peran: baris = fitur (dikelompokkan per modul), kolom = aksi.
 * Peran dipilih lewat ?peran={id}; bawaan = peran pertama selain Super Admin.
 */
class PermissionMatrixController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    public function index(Request $request): View
    {
        $roles = Role::query()->where('guard_name', 'web')->orderBy('id')->get(['id', 'name', 'description']);

        $selected = $roles->firstWhere('id', (int) $request->query('peran'))
            ?? $roles->first(fn (Role $role) => $role->name !== RoleName::SuperAdmin->value)
            ?? $roles->first();

        abort_if($selected === null, 404);

        $user = $request->user();

        return view('access.permissions.index', [
            'roles' => $roles,
            'role' => $selected,
            'modules' => PermissionCatalog::modules(),
            'actions' => PermissionCatalog::actions(),
            'granted' => $selected->permissions()->pluck('name')->flip()->all(),
            'readOnly' => $this->service->isSuperAdmin($selected) || $user->cannot('updatePermissions', $selected),
            'isSuperAdminRole' => $this->service->isSuperAdmin($selected),
            // Selain Super Admin, hanya boleh memberi izin yang dipegang sendiri.
            'grantable' => $user->isSuperAdmin()
                ? null
                : collect(PermissionCatalog::all())->filter(fn (string $permission) => $user->can($permission))->flip()->all(),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role): RedirectResponse
    {
        $result = $this->service->syncPermissions($role, $request->validated('permissions'), $request->user());

        $message = $result['added'] === [] && $result['removed'] === []
            ? "Tidak ada perubahan hak akses untuk {$role->name}."
            : sprintf('Hak akses %s disimpan: %d ditambah, %d dicabut.', $role->name, count($result['added']), count($result['removed']));

        return redirect()->route('permissions.index', ['peran' => $role->getKey()])->with('success', $message);
    }
}
