<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\StatusAbsensi;
use App\Exceptions\AturanBisnisException;
use App\Http\Controllers\Controller;
use App\Http\Requests\JenisCutiRequest;
use App\Models\ApprovalFlow;
use App\Models\JenisCuti;
use App\Models\User;
use App\Services\Approval\ApprovalFlowService;
use App\Services\Sdm\JenisCutiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class JenisCutiController extends Controller
{
    public function __construct(private readonly JenisCutiService $service) {}

    public function index(): View
    {
        return view('jenis-cuti.index', [
            'jenisCuti' => JenisCuti::query()->with('tahap.approver:id,name')->withCount('cutis')->orderBy('nama')->get(),
            'alurCuti' => ApprovalFlow::query()->active()->where('document_type', 'leave')->orderBy('priority')->get(),
        ]);
    }

    /** Buat jenis cuti standar yang belum ada (config sdm.standard_leave_types). */
    public function standard(): RedirectResponse
    {
        $counts = $this->service->lengkapiStandar();

        return redirect()->route('leave-types.index')->with('success', "Jenis cuti standar: {$counts['created']} ditambahkan, {$counts['updated']} dilengkapi aturannya.");
    }

    /**
     * Pindahkan tahap lama jenis cuti ini menjadi alur di mesin persetujuan (Pengaturan → Alur Persetujuan),
     * dengan kondisi "Jenis cuti = ini". Pengajuan baru lalu memakai alur itu; tahap lama dikosongkan.
     */
    public function toFlow(Request $request, JenisCuti $jenisCuti, ApprovalFlowService $flows): RedirectResponse
    {
        $jenisCuti->load('tahap');
        if ($jenisCuti->tahap->isEmpty()) {
            throw new AturanBisnisException("Jenis cuti {$jenisCuti->nama} tidak punya tahap persetujuan untuk dipindahkan.");
        }
        $roles = Role::query()->where('guard_name', 'web')->pluck('id', 'name');

        $flow = DB::transaction(function () use ($jenisCuti, $flows, $roles, $request) {
            $flow = $flows->save(null, [
                'name' => mb_substr("Cuti: {$jenisCuti->nama}", 0, 100),
                'document_type' => 'leave',
                'priority' => 100,
                'is_active' => true,
                'description' => 'Dipindahkan dari tahap persetujuan jenis cuti.',
                'conditions' => [['field' => 'leave_type', 'operator' => '=', 'value' => $jenisCuti->id]],
                'steps' => $jenisCuti->tahap->map(fn ($t) => $t->approver_id
                    ? ['name' => "Tahap {$t->urutan}", 'approver_type' => 'user', 'user_id' => $t->approver_id]
                    : ['name' => "Tahap {$t->urutan} ({$t->peran})", 'approver_type' => 'role', 'role_id' => $roles[$t->peran] ?? null])->all(),
            ], $request->user());
            $jenisCuti->tahap()->delete();

            return $flow;
        });

        return redirect()->route('settings.approval-flows.edit', $flow)
            ->with('success', "Tahap {$jenisCuti->nama} dipindahkan ke alur persetujuan \"{$flow->name}\". Tambahkan tahap atasan langsung bila perlu.");
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
            // Semua peran, termasuk peran buatan sendiri.
            'peran' => Role::query()->where('guard_name', 'web')->orderBy('id')->pluck('name'),
            'statusAbsensi' => StatusAbsensi::untukCuti(),
            // Approver yang sudah nonaktif tetap muncul supaya alur lama terbaca; saat disimpan wajib diganti.
            'pengguna' => User::query()
                ->where(fn ($q) => $q->aktif()->orWhereIn('id', $jenis->tahap->pluck('approver_id')->filter()))
                ->orderBy('name')
                ->get(['id', 'name', 'is_active']),
        ]);
    }
}
