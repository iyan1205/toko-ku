<?php

namespace App\Http\Controllers;

use App\Enums\RoleName;
use App\Enums\StatusAbsensi;
use App\Exceptions\AturanBisnisException;
use App\Http\Requests\JenisCutiRequest;
use App\Models\JenisCuti;
use App\Models\User;
use App\Services\JenisCutiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class JenisCutiController extends Controller
{
    public function __construct(private readonly JenisCutiService $service) {}

    public function index(): View
    {
        return view('jenis-cuti.index', [
            'jenisCuti' => JenisCuti::query()->with('tahap.approver:id,name')->withCount('cutis')->orderBy('nama')->get(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new JenisCuti(['is_active' => true, 'status_absensi' => StatusAbsensi::Cuti]));
    }

    public function store(JenisCutiRequest $request): RedirectResponse
    {
        $jenis = $this->service->simpan($request->validated());

        return redirect()->route('leave-types.index')->with('success', "Jenis cuti {$jenis->nama} tersimpan.");
    }

    public function edit(JenisCuti $jenisCuti): View
    {
        return $this->form($jenisCuti->load('tahap'));
    }

    public function update(JenisCutiRequest $request, JenisCuti $jenisCuti): RedirectResponse
    {
        $this->service->simpan($request->validated(), $jenisCuti);

        return redirect()->route('leave-types.index')
            ->with('success', "Jenis cuti {$jenisCuti->nama} diperbarui. Pengajuan yang sedang berjalan tetap memakai alur lamanya.");
    }

    public function destroy(JenisCuti $jenisCuti): RedirectResponse
    {
        if ($jumlah = $jenisCuti->cutis()->count()) {
            throw new AturanBisnisException("Jenis cuti {$jenisCuti->nama} sudah dipakai {$jumlah} pengajuan. Nonaktifkan saja bila tidak dipakai lagi.");
        }

        $jenisCuti->delete();

        return back()->with('success', "Jenis cuti {$jenisCuti->nama} dihapus.");
    }

    private function form(JenisCuti $jenis): View
    {
        return view('jenis-cuti.form', [
            'jenis' => $jenis,
            'peran' => RoleName::cases(),
            'statusAbsensi' => StatusAbsensi::untukCuti(),
            // Approver yang sudah nonaktif tetap muncul supaya alur lama terbaca; saat disimpan wajib diganti.
            'pengguna' => User::query()
                ->where(fn ($q) => $q->aktif()->orWhereIn('id', $jenis->tahap->pluck('approver_id')->filter()))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
        ]);
    }
}
