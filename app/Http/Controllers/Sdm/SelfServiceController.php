<?php

namespace App\Http\Controllers\Sdm;

use App\Enums\StatusAbsensi;
use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Cuti;
use App\Models\Employee;
use App\Models\JenisCuti;
use App\Models\OvertimeRequest;
use App\Models\ReimbursementRequest;
use App\Models\Scopes\BranchScope;
use App\Services\Sdm\AbsensiService;
use App\Services\Sdm\CutiService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Layanan Mandiri (ESS): karyawan yang punya akun login melihat data dirinya sendiri.
 * Akun yang belum ditautkan ke data karyawan melihat halaman penjelasan. Izin sdm.self-service.view.
 */
class SelfServiceController extends Controller
{
    public function index(Request $request, AbsensiService $attendance, CutiService $leaves): View
    {
        if (! $employee = $this->employee($request)) {
            return view('sdm.ess.unlinked');
        }
        $user = $request->user();

        return view('sdm.ess.index', [
            'employee' => $employee,
            'today' => $attendance->hariIni($user),
            'attendanceRules' => $attendance->aturanAbsen($user),
            'balances' => $this->balances($user, $leaves),
            'pendingOvertime' => $this->overtime($employee)->where('status', 'submitted')->count(),
            'pendingReimbursement' => $this->reimbursements($employee)->where('status', 'submitted')->count(),
            'recent' => $this->recent($employee, $user->id),
        ]);
    }

    public function profile(Request $request): View
    {
        if (! $employee = $this->employee($request)) {
            return view('sdm.ess.unlinked');
        }
        $employee->load(['branch:id,name', 'department:id,name', 'position:id,name', 'supervisor:id,name', 'familyMembers', 'educations', 'currentContract']);

        return view('sdm.ess.profile', ['employee' => $employee]);
    }

    public function attendance(Request $request): View
    {
        if (! $employee = $this->employee($request)) {
            return view('sdm.ess.unlinked');
        }
        $month = $request->validate(['bulan' => ['nullable', 'date_format:Y-m']])['bulan'] ?? now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $rows = Absensi::query()->where('user_id', $request->user()->id)
            ->whereDate('tanggal', '>=', $start->toDateString())->whereDate('tanggal', '<=', $start->endOfMonth()->toDateString())
            ->orderByDesc('tanggal')->get();

        return view('sdm.ess.attendance', [
            'employee' => $employee,
            'month' => $start,
            'rows' => $rows,
            'summary' => collect(StatusAbsensi::cases())->mapWithKeys(fn (StatusAbsensi $s) => [$s->label() => $rows->where('status', $s)->count()]),
        ]);
    }

    public function leaves(Request $request, CutiService $leaves): View
    {
        if (! $employee = $this->employee($request)) {
            return view('sdm.ess.unlinked');
        }

        return view('sdm.ess.leaves', [
            'employee' => $employee,
            'balances' => $this->balances($request->user(), $leaves),
            'requests' => Cuti::query()->where('user_id', $request->user()->id)->with('jenisCuti:id,nama')->latest('tanggal_mulai')->limit(30)->get(),
        ]);
    }

    public function payslips(Request $request): View
    {
        if (! $employee = $this->employee($request)) {
            return view('sdm.ess.unlinked');
        }

        return view('sdm.ess.payslips', ['employee' => $employee]);
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user()->employee;
    }

    /** @return Collection<int, array{type: JenisCuti, left: ?int}> */
    private function balances($user, CutiService $leaves): Collection
    {
        $year = now()->year;

        return JenisCuti::query()->where('is_active', true)->orderBy('nama')->get()
            ->map(fn (JenisCuti $type) => ['type' => $type, 'left' => $leaves->sisaKuota($user, $type, $year)]);
    }

    private function overtime(Employee $employee)
    {
        return OvertimeRequest::withoutGlobalScope(BranchScope::class)->where('employee_id', $employee->id);
    }

    private function reimbursements(Employee $employee)
    {
        return ReimbursementRequest::withoutGlobalScope(BranchScope::class)->where('employee_id', $employee->id);
    }

    /** Pengajuan terbaru (lembur, reimbursement, cuti) untuk beranda. @return Collection<int, array<string, mixed>> */
    private function recent(Employee $employee, int $userId): Collection
    {
        $overtime = $this->overtime($employee)->latest('id')->limit(5)->get()->map(fn (OvertimeRequest $r) => [
            'kind' => 'Lembur', 'title' => $r->durationLabel().' · '.Format::tanggal($r->overtime_date), 'status' => $r->statusLabel(), 'color' => $r->statusColor(), 'at' => $r->created_at, 'url' => route('ess.overtime'),
        ]);
        $reimbursements = $this->reimbursements($employee)->latest('id')->limit(5)->get()->map(fn (ReimbursementRequest $r) => [
            'kind' => 'Reimbursement', 'title' => $r->categoryLabel().' · '.Format::rupiah($r->amount), 'status' => $r->statusLabel(), 'color' => $r->statusColor(), 'at' => $r->created_at, 'url' => route('ess.reimbursements'),
        ]);
        $leaves = Cuti::query()->where('user_id', $userId)->with('jenisCuti:id,nama')->latest('id')->limit(5)->get()->map(fn (Cuti $c) => [
            'kind' => 'Cuti', 'title' => ($c->jenisCuti?->nama ?? 'Cuti').' · '.$c->jumlah_hari.' hari', 'status' => $c->status->label(), 'color' => 'neutral', 'at' => $c->created_at, 'url' => route('leaves.show', $c),
        ]);

        return $overtime->concat($reimbursements)->concat($leaves)->sortByDesc('at')->take(6)->values();
    }
}
