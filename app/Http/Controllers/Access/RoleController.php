<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Services\Access\RoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

/** Daftar peran, tambah peran buatan sendiri, ubah nama/keterangan, hapus. */
class RoleController extends Controller
{
    public function __construct(private readonly RoleService $service) {}

    public function index(): View
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->withCount('permissions')
            ->orderBy('id')
            ->get()
            ->each(fn (Role $role) => $role->setAttribute('users_count', $this->service->userCount($role)));

        return view('access.roles.index', [
            'roles' => $roles,
            'service' => $this->service,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', Role::class);

        return view('access.roles.form', ['role' => new Role, 'builtIn' => false]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = $this->service->create($request->validated());

        return redirect()->route('permissions.index', ['peran' => $role->getKey()])
            ->with('success', "Peran {$role->name} ditambahkan. Sekarang atur hak aksesnya.");
    }

    public function edit(Role $role): View
    {
        Gate::authorize('update', $role);

        return view('access.roles.form', ['role' => $role, 'builtIn' => $this->service->isBuiltIn($role)]);
    }

    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $this->service->update($role, $request->validated());

        return redirect()->route('roles.index')->with('success', "Peran {$role->name} diperbarui.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        Gate::authorize('delete', $role);

        $name = $role->name;
        $this->service->delete($role);

        return redirect()->route('roles.index')->with('success', "Peran {$name} dihapus.");
    }
}
