<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly UserService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $filter = $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'peran' => ['nullable', Rule::in(RoleName::values())],
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

        return view('users.index', [
            'users' => $users,
            'peran' => RoleName::cases(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.form', ['user' => new User(['is_active' => true]), 'peran' => RoleName::cases()]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->service->buat($request->validated());

        return redirect()->route('users.index')
            ->with('success', "Pengguna {$user->name} berhasil ditambahkan.");
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.form', ['user' => $user->load('roles'), 'peran' => RoleName::cases()]);
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

    public function destroy(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $this->service->hapus($user, $request->user());

        return redirect()->route('users.index')
            ->with('success', "Pengguna {$user->name} dihapus.");
    }
}
