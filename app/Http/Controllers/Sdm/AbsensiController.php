<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\StatusAbsensi;
use App\Http\Controllers\Controller;
use App\Http\Requests\AbsensiRequest;
use App\Models\Absensi;
use App\Models\User;
use App\Services\Sdm\AbsensiService;
use App\Services\Sdm\JadwalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AbsensiController extends Controller
{
    public function __construct(private readonly AbsensiService $service) {}

    public function index(Request $request, JadwalService $jadwal): View
    {
        Gate::authorize('viewAny', Absensi::class);

        $filter = $request->validate([
            'pegawai' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::enum(StatusAbsensi::class)],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);

        // Tanpa filter tanggal: tampilkan bulan berjalan.
        if (empty($filter['dari']) && empty($filter['sampai'])) {
            $filter['dari'] = today()->startOfMonth()->toDateString();
            $filter['sampai'] = today()->toDateString();
        }

        $user = $request->user();
        $semuaPegawai = $user->can('sdm.attendances.view');

        if (! $semuaPegawai) {
            $filter['pegawai'] = $user->id;
        }

        // Rekap per status mengikuti pegawai dan periode, tidak ikut filter status.
        $rekap = Absensi::query()
            ->filter(array_diff_key($filter, ['status' => true]))
            ->selectRaw('status, count(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        return view('absensi.index', [
            'absensi' => Absensi::query()->with(['user', 'employee', 'pencatat'])->filter($filter)
                ->orderByDesc('tanggal')->orderBy('user_id')->paginate(20)->withQueryString(),
            'rekap' => collect(StatusAbsensi::cases())->mapWithKeys(fn (StatusAbsensi $s) => [$s->value => (int) ($rekap[$s->value] ?? 0)]),
            'filter' => $filter,
            'semuaPegawai' => $semuaPegawai,
            'pegawai' => $semuaPegawai ? User::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'bisaAbsen' => $user->can('sdm.attendances.record'),
            'hariIni' => $this->service->hariIni($user),
            'aturanAbsen' => $this->service->aturanAbsen($user),
            'jadwalHariIni' => $jadwal->untukTanggal($user, today()),
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        $data = $this->validasiAbsen($request);

        $absensi = $this->service->masuk($request->user(), $data['lokasi'] ?? null, $request->ip(), $request->userAgent(), $request->file('foto'));

        return redirect()->route($request->input('kembali') === 'ess' ? 'ess.index' : 'attendances.index')->with('success', sprintf(
            'Absen masuk tercatat pukul %s (%s).',
            $absensi->clock_in->format('H:i'),
            $absensi->status->label(),
        ));
    }

    public function clockOut(Request $request): RedirectResponse
    {
        $data = $this->validasiAbsen($request);

        $absensi = $this->service->pulang($request->user(), $data['lokasi'] ?? null, $request->file('foto'));

        return redirect()->route($request->input('kembali') === 'ess' ? 'ess.index' : 'attendances.index')
            ->with('success', 'Absen pulang tercatat pukul '.$absensi->clock_out->format('H:i').'. Terima kasih!');
    }

    /**
     * Foto selfie absen: hanya pemilik absensi atau yang boleh melihat absensi semua karyawan.
     */
    public function photo(Request $request, Absensi $absensi, string $jenis): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('sdm.attendances.view') || $absensi->user_id === $user->id, 403);

        $path = $jenis === 'pulang' ? $absensi->clock_out_photo : $absensi->clock_in_photo;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    /** @return array{lokasi?: ?string, foto?: mixed} */
    private function validasiAbsen(Request $request): array
    {
        return $request->validate([
            'lokasi' => ['nullable', 'string', 'max:50', 'regex:/^-?\d{1,2}(\.\d+)?,-?\d{1,3}(\.\d+)?$/'],
            // Foto dikecilkan di browser; batas ini untuk berjaga bila browser tidak mendukungnya.
            'foto' => ['nullable', 'image', 'max:4096'],
        ], [
            'lokasi.regex' => 'Lokasi tidak valid. Muat ulang halaman lalu coba lagi.',
            'foto.image' => 'Foto selfie harus berupa gambar.',
            'foto.max' => 'Foto selfie terlalu besar (maks. 4 MB).',
            'foto.uploaded' => 'Foto selfie gagal diunggah. Coba ambil ulang dengan ukuran lebih kecil.',
        ]);
    }

    public function create(): View
    {
        return view('absensi.form', [
            'absensi' => new Absensi(['tanggal' => today(), 'status' => StatusAbsensi::Izin]),
            'pegawai' => User::query()->aktif()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(AbsensiRequest $request): RedirectResponse
    {
        $absensi = $this->service->simpan($request->validated(), $request->user());

        return redirect()->route('attendances.index', ['dari' => $absensi->tanggal->toDateString(), 'sampai' => $absensi->tanggal->toDateString()])
            ->with('success', "Absensi {$absensi->user->name} tanggal {$absensi->tanggal->format('d-m-Y')} tersimpan.");
    }

    public function edit(Absensi $absensi): View
    {
        return view('absensi.form', [
            'absensi' => $absensi->load(['user', 'pencatat']),
            // Pegawai yang sudah dinonaktifkan tetap ada di pilihan supaya catatan lamanya bisa dikoreksi.
            'pegawai' => User::query()->where(fn ($q) => $q->aktif()->orWhere('id', $absensi->user_id))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(AbsensiRequest $request, Absensi $absensi): RedirectResponse
    {
        $this->service->simpan($request->validated(), $request->user(), $absensi);

        return redirect()->route('attendances.index', ['dari' => $absensi->tanggal->toDateString(), 'sampai' => $absensi->tanggal->toDateString()])
            ->with('success', "Absensi {$absensi->user->name} tanggal {$absensi->tanggal->format('d-m-Y')} diperbarui.");
    }

    public function destroy(Absensi $absensi): RedirectResponse
    {
        $absensi->delete();

        return back()->with('success', "Absensi {$absensi->user->name} tanggal {$absensi->tanggal->format('d-m-Y')} dihapus.");
    }
}
