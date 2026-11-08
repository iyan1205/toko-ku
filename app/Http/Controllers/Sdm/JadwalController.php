<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\StatusCuti;
use App\Http\Controllers\Controller;
use App\Http\Requests\JadwalRequest;
use App\Models\Cuti;
use App\Models\Jadwal;
use App\Models\Shift;
use App\Models\User;
use App\Services\Sdm\JadwalService;
use App\Support\Format;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class JadwalController extends Controller
{
    public function __construct(private readonly JadwalService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Jadwal::class);

        $minggu = $request->validate(['minggu' => ['nullable', 'date_format:Y-m-d']])['minggu'] ?? null;
        $senin = $this->service->awalMinggu($minggu);
        $akhirMinggu = $senin->addDays(6);

        $pegawai = User::query()->aktif()->orderBy('name')->get(['id', 'name']);
        $idPegawai = $pegawai->pluck('id')->all();

        // Cuti yang sudah disetujui ditandai di grid, dengan kunci "user_id|Y-m-d".
        $cuti = Cuti::query()
            ->with('jenisCuti:id,nama')
            ->where('status', StatusCuti::Disetujui)
            ->whereIn('user_id', $idPegawai)
            ->whereDate('tanggal_mulai', '<=', $akhirMinggu->toDateString())
            ->whereDate('tanggal_selesai', '>=', $senin->toDateString())
            ->get()
            ->flatMap(fn (Cuti $c) => collect(CarbonPeriod::create($c->tanggal_mulai, $c->tanggal_selesai))
                ->mapWithKeys(fn ($tanggal) => [$c->user_id.'|'.$tanggal->toDateString() => $c->jenisCuti->nama]));

        return view('jadwal.index', [
            'senin' => $senin,
            'hari' => $this->service->hariDalamMinggu($senin),
            'pegawai' => $pegawai,
            'jadwal' => $this->service->dalamMinggu($senin, $idPegawai),
            'cuti' => $cuti,
            'shifts' => Shift::query()->orderBy('jam_masuk')->orderBy('nama')->get(),
            'bisaUbah' => $request->user()->can('manage-schedules'),
        ]);
    }

    public function update(JadwalRequest $request): RedirectResponse
    {
        $senin = $this->service->awalMinggu($request->validated('minggu'));

        $this->service->simpanMinggu($senin, $request->validated('jadwal') ?? []);

        return redirect()->route('schedules.index', ['minggu' => $senin->toDateString()])
            ->with('success', 'Jadwal '.Format::tanggal($senin).' s/d '.Format::tanggal($senin->addDays(6)).' tersimpan.');
    }

    public function copy(Request $request): RedirectResponse
    {
        $senin = $this->service->awalMinggu($request->validate(['minggu' => ['required', 'date_format:Y-m-d']])['minggu']);

        $jumlah = $this->service->salinDariMingguLalu($senin, User::query()->aktif()->pluck('id')->all());

        return redirect()->route('schedules.index', ['minggu' => $senin->toDateString()])->with(
            $jumlah > 0 ? 'success' : 'error',
            $jumlah > 0
                ? "{$jumlah} jadwal disalin dari minggu lalu. Sel yang sudah terisi tidak diubah."
                : 'Tidak ada jadwal minggu lalu yang bisa disalin ke sel yang masih kosong.',
        );
    }
}
