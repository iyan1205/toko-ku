<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BranchRequest;
use App\Models\Branch;
use App\Services\Settings\BranchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/** Cabang/outlet. Izin settings.branches.* dipasang di routes/modules/settings.php. */
class BranchController extends Controller
{
    public function __construct(private readonly BranchService $branches) {}

    public function index(): View
    {
        return view('settings.branches.index', [
            'branches' => Branch::query()->ordered()->get(),
        ]);
    }

    public function create(): View
    {
        return view('settings.branches.form', ['branch' => new Branch(['is_active' => true])]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = $this->branches->create($request->validated());

        return redirect()->route('settings.branches.index')->with('success', "Cabang {$branch->name} ditambahkan.");
    }

    public function edit(Branch $branch): View
    {
        return view('settings.branches.form', ['branch' => $branch]);
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->branches->update($branch, $request->validated());

        return redirect()->route('settings.branches.index')->with('success', "Cabang {$branch->name} diperbarui.");
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $this->branches->delete($branch);

        return redirect()->route('settings.branches.index')->with('success', "Cabang {$branch->name} dihapus.");
    }
}
