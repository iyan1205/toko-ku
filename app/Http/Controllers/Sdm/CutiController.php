<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\StatusCuti;
use App\Http\Controllers\Controller;
use App\Http\Requests\CutiRequest;
use App\Models\Absensi;
use App\Models\ApprovalFlow;
use App\Models\Cuti;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JenisCuti;
use App\Models\User;
use App\Services\Sdm\CutiService;
use App\Services\Sdm\WorkCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CutiController extends Controller
{
    public function __construct(private readonly CutiService $service) {}

    /** Tab: saya (pengajuan sendiri), persetujuan (menunggu keputusan saya), semua (sdm.leaves.view). */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Cuti::class);

        $user = $request->user();
        $bisaLihatSemua = $user->can('sdm.leaves.view');

        $filter = $request->validate([
            'tab' => ['nullable', Rule::in(['saya', 'persetujuan', 'semua'])],
            'status' => ['nullable', Rule::enum(StatusCuti::class)],
            'jenis' => ['nullable', 'integer'],
            'pegawai' => ['nullable', 'integer'],
            'tahun' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);
        $tab = $filter['tab'] ?? ($user->can('sdm.leaves.create') ? 'saya' : 'persetujuan');

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
            'bisaMengajukan' => $user->can('sdm.leaves.create'),
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

    /**
     * Kalender cuti tim: karyawan × tanggal dalam sebulan (disetujui dan menunggu). Tanpa izin
     * sdm.leaves.view hanya departemen sendiri, supaya karyawan bisa melihat siapa yang sedang cuti.
     */
    public function calendar(Request $request, WorkCalendar $calendar): View
    {
        Gate::authorize('viewAny', Cuti::class);

        $user = $request->user();
        $semua = $user->can('sdm.leaves.view');
        $filter = $request->validate(['bulan' => ['nullable', 'date_format:Y-m'], 'departemen' => ['nullable', 'integer']]);
        $start = CarbonImmutable::createFromFormat('!Y-m', $filter['bulan'] ?? today()->format('Y-m'))->startOfMonth();
        $end = $start->endOfMonth()->startOfDay();
        $departemen = $semua ? ($filter['departemen'] ?? null) : $user->employee?->department_id;

        $employees = Employee::query()->where('is_active', true)
            ->when($departemen, fn ($q, $id) => $q->where('department_id', $id))
            ->when(! $semua && $departemen === null, fn ($q) => $q->whereKey($user->employee?->id ?? 0))
            ->orderBy('name')->get(['id', 'code', 'name', 'user_id', 'department_id', 'branch_id']);
        $byUser = $employees->whereNotNull('user_id')->pluck('id', 'user_id');

        $cells = [];
        Cuti::query()->with('jenisCuti:id,nama')
            ->whereIn('status', [StatusCuti::Disetujui, StatusCuti::Menunggu])
            ->where(fn ($q) => $q->whereIn('employee_id', $employees->pluck('id'))->orWhereIn('user_id', $byUser->keys()))
            ->whereDate('tanggal_mulai', '<=', $end->toDateString())->whereDate('tanggal_selesai', '>=', $start->toDateString())
            ->get()
            ->each(function (Cuti $c) use (&$cells, $byUser, $start, $end) {
                $employeeId = $c->employee_id ?? $byUser[$c->user_id] ?? null;
                for ($d = CarbonImmutable::parse($c->tanggal_mulai)->max($start); $d->lte(CarbonImmutable::parse($c->tanggal_selesai)->min($end)); $d = $d->addDay()) {
                    $cells[$employeeId.'|'.$d->toDateString()] = $c;
                }
            });

        return view('cuti.calendar', [
            'start' => $start,
            'dates' => collect(CarbonPeriod::create($start, $end))->map(fn ($d) => CarbonImmutable::parse($d)),
            'employees' => $employees,
            'cells' => $cells,
            'holidays' => $calendar->holidays($start, $end, null),
            'weeklyOff' => $calendar->weeklyOffDays(),
            'filter' => $filter,
            'semua' => $semua,
            'departments' => $semua ? Department::query()->orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Cuti::class);

        $user = $request->user();
        $tahun = (int) today()->year;
        $employee = $user->employee;

        return view('cuti.create', [
            'jenisCuti' => JenisCuti::query()->aktif()->with('tahap.approver:id,name')->orderBy('nama')->get()
                ->filter(fn (JenisCuti $j) => $j->gender === null || $employee?->gender === null || $employee->gender === $j->gender)
                ->values()
                ->map(fn (JenisCuti $j) => [
                    'id' => $j->id,
                    'nama' => $j->nama,
                    'kuota' => $j->quotaFor($employee, $tahun),
                    // Sisa kuota mengikuti tahun tanggal mulai; form memilih sesuai tanggal yang diisi.
                    'sisa' => $j->quotaFor($employee, $tahun) !== null
                        ? [$tahun => $this->service->sisaKuota($user, $j, $tahun), $tahun + 1 => $this->service->sisaKuota($user, $j, $tahun + 1, today()->addYear()->startOfYear())]
                        : null,
                    'alur' => $j->tahap->map->label()->all(),
                    'hariKerja' => $j->working_days_only && $employee !== null,
                    'surat' => $j->requires_document ? $j->document_after_days : null,
                    'aturan' => array_values(array_filter([
                        $j->is_paid ? null : 'Tanpa upah (dipotong saat penggajian).',
                        $j->max_days_per_request ? "Paling lama {$j->max_days_per_request} hari per pengajuan." : null,
                        $j->min_service_months ? "Setelah masa kerja {$j->min_service_months} bulan." : null,
                        $j->requires_document ? ($j->document_after_days > 0 ? "Lebih dari {$j->document_after_days} hari wajib surat keterangan." : 'Wajib surat keterangan.') : null,
                    ])),
                ]),
            'lewatMesin' => ApprovalFlow::query()->active()->where('document_type', 'leave')->exists(),
        ]);
    }

    public function store(CutiRequest $request): RedirectResponse
    {
        $cuti = $this->service->ajukan($request->user(), collect($request->validated())->except('dokumen')->all(), $request->file('dokumen', []));

        $menunggu = $cuti->pendingApproval()?->currentStep()['name'] ?? $cuti->tahapSekarang()?->label();
        $pesan = $cuti->status === StatusCuti::Disetujui
            ? "Pengajuan {$cuti->nomor} langsung disetujui dan tercatat di absensi."
            : "Pengajuan {$cuti->nomor} terkirim. Menunggu persetujuan {$menunggu}.";

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

        $cuti = $this->service->batalkan($cuti, request()->user());

        return redirect()->route('leaves.show', $cuti)->with('success', "Pengajuan {$cuti->nomor} dibatalkan. Kuotanya dikembalikan.");
    }
}
