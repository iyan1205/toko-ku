<?php

namespace App\Http\Controllers\Sdm;

use App\Exports\ListExport;
use App\Exports\Sdm\AttendanceCorrectionsExport;
use App\Exports\Sdm\OvertimeRequestsExport;
use App\Exports\Sdm\ReimbursementRequestsExport;
use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrection;
use App\Models\OvertimeRequest;
use App\Models\ReimbursementRequest;
use App\Services\Sdm\EmployeeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * SDM → Lembur, Reimbursement, dan Koreksi Absensi: daftar semua pengajuan (cabang aktif), ekspor, dan keputusan
 * langsung untuk pengajuan tanpa alur persetujuan. Jenis dari parameter route {jenis}: lembur | reimbursement.
 */
class EmployeeRequestController extends Controller
{
    /** Kunci = awalan nama route (overtime.*, reimbursements.*). */
    private const KINDS = [
        'overtime' => ['model' => OvertimeRequest::class, 'export' => OvertimeRequestsExport::class, 'view' => 'sdm.requests.overtime', 'route' => 'overtime', 'permission' => 'sdm.overtime'],
        'reimbursements' => ['model' => ReimbursementRequest::class, 'export' => ReimbursementRequestsExport::class, 'view' => 'sdm.requests.reimbursements', 'route' => 'reimbursements', 'permission' => 'sdm.reimbursements'],
        'attendance-corrections' => ['model' => AttendanceCorrection::class, 'export' => AttendanceCorrectionsExport::class, 'view' => 'sdm.requests.corrections', 'route' => 'attendance-corrections', 'permission' => 'sdm.attendance-corrections'],
    ];

    public function __construct(private readonly EmployeeRequestService $requests) {}

    public function index(Request $request): View
    {
        $kind = $this->kind($request);

        return view($kind['view'], [
            'items' => $kind['export']::fromRequest($request, $this->filter($request))->paginate(15),
            'routePrefix' => $kind['route'],
            'permission' => $kind['permission'],
        ]);
    }

    public function export(Request $request): Response
    {
        $kind = $this->kind($request);

        return $kind['export']::fromRequest($request, $this->filter($request))->download(ListExport::formatFrom($request));
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $item = $this->find($request, $id);
        $this->requests->approve($item, $request->user());

        return back()->with('success', "{$item->number} disetujui.");
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'min:5', 'max:200']], [], ['alasan' => 'alasan']);
        $item = $this->find($request, $id);
        $this->requests->reject($item, $request->user(), $request->input('alasan'));

        return back()->with('success', "{$item->number} ditolak.");
    }

    /** @return array{model: class-string, export: class-string<ListExport>, view: string, route: string, permission: string} */
    private function kind(Request $request): array
    {
        return self::KINDS[Str::before((string) $request->route()?->getName(), '.')] ?? abort(404);
    }

    private function find(Request $request, int $id): OvertimeRequest|ReimbursementRequest|AttendanceCorrection
    {
        return $this->kind($request)['model']::query()->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'cari' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['submitted', 'approved', 'rejected', 'cancelled'])],
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
        ]);
    }
}
