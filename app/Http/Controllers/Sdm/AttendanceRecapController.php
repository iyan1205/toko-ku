<?php

namespace App\Http\Controllers\Sdm;

use App\Exports\Sdm\AttendanceRecapExport;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Sdm\AttendanceRecapService;
use App\Services\Settings\StoreProfileService;
use App\Support\Format;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * SDM → Rekap Absensi: ringkasan per karyawan dalam satu periode (siap dipakai penggajian),
 * rincian harian per karyawan, dan ekspor Excel/PDF. Upah lembur hanya untuk pemegang sdm.payroll.view.
 */
class AttendanceRecapController extends Controller
{
    public function __construct(private readonly AttendanceRecapService $recap) {}

    public function index(Request $request): View
    {
        $filter = $this->filter($request);
        [$from, $to] = $this->recap->period($filter['bulan']);
        $withPay = $request->user()->can('sdm.payroll.view');
        $employees = $this->employees($filter, $from)->paginate(25)->withQueryString();

        return view('sdm.attendance-recap.index', [
            'filter' => $filter,
            'from' => $from,
            'to' => $to,
            'employees' => $employees,
            'summaries' => $this->recap->summarize($employees->getCollection(), $from, $to, $withPay),
            'columns' => AttendanceRecapExport::columns($withPay),
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id'),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(Request $request, Employee $employee): View
    {
        $filter = $this->filter($request);
        [$from, $to] = $this->recap->period($filter['bulan']);
        $withPay = $request->user()->can('sdm.payroll.view');
        $employee->load('department:id,name', 'branch:id,name');

        return view('sdm.attendance-recap.show', [
            'employee' => $employee,
            'filter' => $filter,
            'from' => $from,
            'to' => $to,
            'days' => $this->recap->daily($employee, $from, $to),
            'summary' => $this->recap->summarize([$employee], $from, $to, $withPay)[$employee->id],
            'withPay' => $withPay,
        ]);
    }

    public function export(Request $request, StoreProfileService $store): Response
    {
        $filter = $this->filter($request);
        $format = $request->validate(['format' => ['nullable', Rule::in(['xlsx', 'pdf'])]])['format'] ?? 'xlsx';
        [$from, $to] = $this->recap->period($filter['bulan']);
        $withPay = $request->user()->can('sdm.payroll.view');
        $summaries = $this->recap->summarize($this->employees($filter, $from)->get(), $from, $to, $withPay);
        $label = Format::tanggal($from).' – '.Format::tanggal($to);
        $name = 'rekap-absensi-'.$filter['bulan'];

        if ($format === 'pdf') {
            return Pdf::loadView('sdm.attendance-recap.pdf', [
                'summaries' => $summaries, 'columns' => AttendanceRecapExport::columns($withPay), 'periodLabel' => $label, 'store' => $store->ambil(),
            ])->setPaper('a4', 'landscape')->download("{$name}.pdf");
        }

        return Excel::download(new AttendanceRecapExport($summaries, $label, $withPay, $store->ambil()), "{$name}.xlsx");
    }

    /** @return array{bulan: string, departemen?: ?int, cabang?: ?int, cari?: ?string} */
    private function filter(Request $request): array
    {
        $filter = $request->validate([
            'bulan' => ['nullable', 'date_format:Y-m'],
            'departemen' => ['nullable', 'integer'],
            'cabang' => ['nullable', 'integer'],
            'cari' => ['nullable', 'string', 'max:100'],
        ]);
        $filter['bulan'] ??= today()->format('Y-m');

        return $filter;
    }

    /** Karyawan yang aktif pada periode itu (termasuk yang berhenti di tengah periode). */
    private function employees(array $filter, CarbonImmutable $from): Builder
    {
        return Employee::query()->with('department:id,name')
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereDate('end_date', '>=', $from->toDateString()))
            ->when($filter['departemen'] ?? null, fn (Builder $q, $v) => $q->where('department_id', $v))
            ->when($filter['cabang'] ?? null, fn (Builder $q, $v) => $q->where('branch_id', $v))
            ->when($filter['cari'] ?? null, fn (Builder $q, $v) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")))
            ->orderBy('name');
    }
}
