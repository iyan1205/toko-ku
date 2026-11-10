<?php

namespace App\Http\Controllers\Sdm;

use App\Exceptions\AturanBisnisException;
use App\Exports\ListExport;
use App\Exports\Sdm\OrganizationUnitsExport;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Halaman departemen dan jabatan: satu pola (nama + deskripsi + jumlah karyawan) memakai view
 * bersama inventory/master/index. Izin: sdm.departments.* (dipasang di routes/modules/sdm.php).
 */
abstract class OrganizationUnitController extends Controller
{
    /** @return class-string<OrganizationUnitsExport> */
    abstract protected function exportClass(): string;

    abstract protected function label(): string;

    abstract protected function prefix(): string;

    public function index(Request $request): View
    {
        $class = $this->exportClass();

        return view('inventory.master.index', [
            'judul' => ucfirst($this->label()),
            'tunggal' => $this->label(),
            'prefix' => $this->prefix(),
            'izin' => 'sdm.departments',
            'items' => $class::fromRequest($request, $this->filter($request))->paginate(15),
            'hitungLabel' => 'Jumlah karyawan',
            'hitungKolom' => 'employees_count',
            'hitungSort' => 'employees',
            'keterangan' => ucfirst($this->label()).' yang masih dipakai karyawan tidak bisa dihapus.',
            'ikon' => 'user-group',
            'tabs' => [
                ['label' => 'Departemen', 'url' => route('departments.index'), 'active' => $this->prefix() === 'departments'],
                ['label' => 'Jabatan', 'url' => route('positions.index'), 'active' => $this->prefix() === 'positions'],
            ],
        ]);
    }

    public function export(Request $request): Response
    {
        $format = ListExport::formatFrom($request);
        $class = $this->exportClass();

        return $class::fromRequest($request, $this->filter($request))->download($format);
    }

    protected function storeUnit(FormRequest $request, string $model): RedirectResponse
    {
        $unit = $model::create($request->validated());

        return back()->with('success', ucfirst($this->label())." {$unit->name} berhasil ditambahkan.");
    }

    protected function updateUnit(FormRequest $request, Model $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return back()->with('success', ucfirst($this->label())." {$unit->name} berhasil diperbarui.");
    }

    protected function destroyUnit(Model $unit): RedirectResponse
    {
        if ($jumlah = $unit->dipakaiKaryawan()) {
            throw new AturanBisnisException(ucfirst($this->label())." {$unit->name} tidak dapat dihapus karena masih dipakai {$jumlah} karyawan.");
        }

        $unit->delete();

        return back()->with('success', ucfirst($this->label())." {$unit->name} dihapus.");
    }

    /** @return array<string, mixed> */
    private function filter(Request $request): array
    {
        return $request->validate(['cari' => ['nullable', 'string', 'max:100']]);
    }
}
