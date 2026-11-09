<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ganti cabang aktif dari topbar. Hanya cabang aktif yang bisa dipilih. */
class CurrentBranchController extends Controller
{
    public function __invoke(Request $request, CurrentBranch $currentBranch): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ], [], ['branch_id' => 'cabang']);

        $branch = Branch::query()->findOrFail($data['branch_id']);
        $currentBranch->set($branch);

        return back()->with('success', "Cabang aktif: {$branch->name}.");
    }
}
