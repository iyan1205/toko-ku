<?php

namespace App\Http\Controllers\Access;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\Access\UserService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'peran' => ['nullable', 'string', Rule::exists(config('permission.table_names.roles'), 'name')->where('guard_name', 'web')],
            'status' => ['nullable', Rule::in(['aktif', 'nonaktif'])],
        ]);

        $users = User::query()
            ->with('roles')
            ->cari($filter['cari'] ?? null)
            ->when($filter['peran'] ?? null, fn ($q, $peran) => $q->role($peran))
            ->when($filter['status'] ?? null, fn ($q, $status) => $q->where('is_active', $status === 'aktif'))
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        return view('access.users.index', [
            'users' => $users,
            'peran' => Role::query()->where('guard_name', 'web')->orderBy('id')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', User::class);

        return view('access.users.form', [
            'user' => new User(['is_active' => true]),
            'peran' => $this->assignableRoles($request->user()),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->service->buat($request->validated());

        return redirect()->route('users.index')
            ->with('success', "Pengguna {$user->name} berhasil ditambahkan.");
    }

    public function edit(Request $request, User $user): View
    {
        Gate::authorize('update', $user);

        return view('access.users.form', [
            'user' => $user->load('roles'),
            'peran' => $this->assignableRoles($request->user()),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->service->perbarui($user, $request->validated(), $request->user());

        return redirect()->route('users.index')
            ->with('success', "Data {$user->name} berhasil diperbarui.");
    }

    public function toggleStatus(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $user = $this->service->ubahStatus($user, $request->user());

        return back()->with('success', $user->is_active
            ? "Akun {$user->name} diaktifkan."
            : "Akun {$user->name} dinonaktifkan.");
    }

    /** Admin menetapkan kata sandi baru (pengguna lupa kata sandi). */
    public function resetPassword(ResetUserPasswordRequest $request, User $user): RedirectResponse
    {
        $this->service->resetKataSandi($user, $request->validated('password'), $request->user());

        return back()->with('success', "Kata sandi {$user->name} berhasil direset.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $this->service->hapus($user, $request->user());

        return redirect()->route('users.index')
            ->with('success', "Pengguna {$user->name} dihapus.");
    }

    /**
     * Peran yang boleh diberikan: semua peran, kecuali Super Admin bila yang login bukan Super Admin.
     *
     * @return Collection<int, Role>
     */
    private function assignableRoles(User $actor): Collection
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('name', '!=', RoleName::SuperAdmin->value))
            ->orderBy('id')
            ->get(['id', 'name', 'description']);
    }
}
