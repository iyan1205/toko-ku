<?php

namespace App\Http\Controllers;

use App\Enums\StatusCuti;
use App\Http\Requests\CutiRequest;
use App\Models\Absensi;
use App\Models\Cuti;
use App\Models\JenisCuti;
use App\Models\User;
use App\Services\CutiService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CutiController extends Controller
{
    public function __construct(private readonly CutiService $service) {}

    /** Tab: saya (pengajuan sendiri), persetujuan (menunggu keputusan saya), semua (view-leaves). */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Cuti::class);

        $user = $request->user();
        $bisaLihatSemua = $user->can('view-leaves');

        $filter = $request->validate([
            'tab' => ['nullable', Rule::in(['saya', 'persetujuan', 'semua'])],
            'status' => ['nullable', Rule::enum(StatusCuti::class)],
            'jenis' => ['nullable', 'integer'],
            'pegawai' => ['nullable', 'integer'],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);
        $tab = $filter['tab'] ?? ($user->can('request-leave') ? 'saya' : 'persetujuan');

        abort_if($tab === 'semua' && ! $bisaLihatSemua, 403);

        $query = match ($tab) {
            'saya' => Cuti::query()->where('user_id', $user->id)->filter(array_diff_key($filter, ['pegawai' => true])),
            'persetujuan' => Cuti::query()->perluDiputuskanOleh($user),
            'semua' => Cuti::query()->filter($filter),
        };

        $tahunIni = (int) today()->year;
        $terpakai = $this->service->terpakaiPerJenis($user, $tahunIni);

        return view('cuti.index', [
            'tab' => $tab,
            'filter' => $filter,
            'cuti' => $query->with(['user:id,name', 'jenisCuti:id,nama', 'persetujuan.approver:id,name'])
                ->orderByDesc('tanggal_mulai')->orderByDesc('id')->paginate(15)->withQueryString(),
            'jumlahPerluPersetujuan' => Cuti::query()->perluDiputuskanOleh($user)->count(),
            'bisaLihatSemua' => $bisaLihatSemua,
            'bisaMengajukan' => $user->can('request-leave'),
            'tahunIni' => $tahunIni,
            'kuota' => JenisCuti::query()->aktif()->whereNotNull('kuota_tahunan')->orderBy('nama')->get()
                ->map(fn (JenisCuti $j) => [
                    'nama' => $j->nama,
                    'kuota' => $j->kuota_tahunan,
                    'terpakai' => (int) $terpakai->get($j->id, 0),
                ]),
            'jenisCuti' => JenisCuti::query()->orderBy('nama')->get(['id', 'nama']),
            'pegawai' => $bisaLihatSemua ? User::query()->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Cuti::class);

        $user = $request->user();
        $tahun = (int) today()->year;
        $terpakai = [$tahun => $this->service->terpakaiPerJenis($user, $tahun), $tahun + 1 => $this->service->terpakaiPerJenis($user, $tahun + 1)];

        return view('cuti.create', [
            'jenisCuti' => JenisCuti::query()->aktif()->with('tahap.approver:id,name')->orderBy('nama')->get()
                ->map(fn (JenisCuti $j) => [
                    'id' => $j->id,
                    'nama' => $j->nama,
                    'kuota' => $j->kuota_tahunan,
                    // Sisa kuota mengikuti tahun tanggal mulai; form memilih sesuai tanggal yang diisi.
                    'sisa' => $j->punyaKuota()
                        ? collect($terpakai)->map(fn ($perJenis) => max(0, $j->kuota_tahunan - (int) $perJenis->get($j->id, 0)))->all()
                        : null,
                    'alur' => $j->tahap->map->label()->all(),
                ]),
        ]);
    }

    public function store(CutiRequest $request): RedirectResponse
    {
        $cuti = $this->service->ajukan($request->user(), $request->validated());

        $pesan = $cuti->status === StatusCuti::Disetujui
            ? "Pengajuan {$cuti->nomor} langsung disetujui dan tercatat di absensi."
            : "Pengajuan {$cuti->nomor} terkirim. Menunggu persetujuan {$cuti->tahapSekarang()->label()}.";

        return redirect()->route('leaves.show', $cuti)->with('success', $pesan);
    }

    public function show(Request $request, Cuti $cuti): View
    {
        $cuti->load(['user:id,name', 'jenisCuti', 'persetujuan.approver:id,name', 'persetujuan.pemutus:id,name']);

        Gate::authorize('view', $cuti);

        return view('cuti.show', [
            'cuti' => $cuti,
            'bisaMemutuskan' => $request->user()->can('decide', $cuti),
            'bisaBatal' => $request->user()->can('cancel', $cuti),
            // Status absensi tiap tanggal cuti, supaya approver tahu bila pegawai ternyata tetap masuk.
            'absensi' => Absensi::query()
                ->where('user_id', $cuti->user_id)
                ->whereDate('tanggal', '>=', $cuti->tanggal_mulai->toDateString())
                ->whereDate('tanggal', '<=', $cuti->tanggal_selesai->toDateString())
                ->orderBy('tanggal')
                ->get(),
        ]);
    }

    public function approve(Request $request, Cuti $cuti): RedirectResponse
    {
        Gate::authorize('decide', $cuti);

        $catatan = $request->validate(['catatan' => ['nullable', 'string', 'max:255']])['catatan'] ?? null;

        $cuti = $this->service->setujui($cuti, $request->user(), $catatan);

        $pesan = $cuti->status === StatusCuti::Disetujui
            ? "Pengajuan {$cuti->nomor} disetujui penuh dan tercatat di absensi."
            : "Tahap disetujui. Pengajuan {$cuti->nomor} lanjut ke {$cuti->tahapSekarang()->label()}.";

        return redirect()->route('leaves.show', $cuti)->with('success', $pesan);
    }

    public function reject(Request $request, Cuti $cuti): RedirectResponse
    {
        Gate::authorize('decide', $cuti);

        $catatan = $request->validate(
            ['catatan' => ['required', 'string', 'max:255']],
            ['catatan.required' => 'Tuliskan alasan penolakan supaya pemohon tahu.'],
        )['catatan'];

        $cuti = $this->service->tolak($cuti, $request->user(), $catatan);

        return redirect()->route('leaves.show', $cuti)->with('success', "Pengajuan {$cuti->nomor} ditolak.");
    }

    public function cancel(Cuti $cuti): RedirectResponse
    {
        Gate::authorize('cancel', $cuti);

        $cuti = $this->service->batalkan($cuti);

        return redirect()->route('leaves.show', $cuti)->with('success', "Pengajuan {$cuti->nomor} dibatalkan. Kuotanya dikembalikan.");
    }
}
