<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\CurrentBranch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ganti cabang aktif dari topbar. Hanya cabang aktif yang boleh diakses pengguna yang bisa dipilih;
 * "semua" memilih Semua cabang (bila boleh mengakses lebih dari satu cabang).
 */
class CurrentBranchController extends Controller
{
    public function __invoke(Request $request, CurrentBranch $currentBranch): RedirectResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'regex:/^(semua|\d{1,10})$/']], [], ['branch_id' => 'cabang']);

        if ((string) $data['branch_id'] === 'semua') {
            if (! $currentBranch->canChooseAll()) {
                throw ValidationException::withMessages(['branch_id' => 'Anda hanya bisa mengakses satu cabang.']);
            }

            $currentBranch->set(null);

            return back()->with('success', 'Menampilkan data semua cabang.');
        }

        $branch = $currentBranch->options()->firstWhere('id', (int) $data['branch_id']);

        if (! $branch instanceof Branch) {
            throw ValidationException::withMessages(['branch_id' => 'Cabang tidak ditemukan, nonaktif, atau di luar akses Anda.']);
        }

        $currentBranch->set($branch);

        return back()->with('success', "Cabang aktif: {$branch->name}.");
    }
}
