<?php

namespace App\Exports\Sdm;

use App\Enums\EmploymentType;
use App\Exports\ListExport;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;

/** Daftar karyawan. */
class EmployeesExport extends ListExport
{
    public function documentTitle(): string
    {
        return 'Daftar Karyawan';
    }

    protected function baseQuery(): Builder
    {
        return Employee::query()->with(['department:id,name', 'position:id,name', 'user:id,name,email'])->filter($this->filters);
    }

    protected function defaultOrder(Builder $query): void
    {
        $query->orderBy('name');
    }

    protected function sorts(): array
    {
        return ['code' => 'code', 'name' => 'name', 'join' => 'join_date'];
    }

    protected function columns(): array
    {
        return [
            ['label' => 'Kode', 'value' => fn (Employee $e) => $e->code],
            ['label' => 'Nama', 'value' => fn (Employee $e) => $e->name],
            ['label' => 'Departemen', 'value' => fn (Employee $e) => $e->department?->name],
            ['label' => 'Jabatan', 'value' => fn (Employee $e) => $e->position?->name],
            ['label' => 'Status Kerja', 'value' => fn (Employee $e) => $e->employment_type->label()],
            ['label' => 'Mulai Kerja', 'value' => fn (Employee $e) => $e->join_date, 'type' => 'date'],
            ['label' => 'Telepon', 'value' => fn (Employee $e) => $e->phone],
            ['label' => 'Email', 'value' => fn (Employee $e) => $e->email],
            ['label' => 'Akun Login', 'value' => fn (Employee $e) => $e->user?->email],
            ['label' => 'Status', 'value' => fn (Employee $e) => $e->is_active ? 'Aktif' : 'Tidak aktif'],
        ];
    }

    protected function filterSummary(): array
    {
        $f = $this->filters;

        return array_filter([
            'Pencarian' => $f['cari'] ?? null,
            'Departemen' => isset($f['departemen']) ? Department::find($f['departemen'])?->name : null,
            'Jabatan' => isset($f['jabatan']) ? Position::find($f['jabatan'])?->name : null,
            'Status kerja' => isset($f['jenis']) ? EmploymentType::tryFrom($f['jenis'])?->label() : null,
            'Status' => isset($f['status']) ? ($f['status'] === 'aktif' ? 'Aktif' : 'Tidak aktif') : null,
        ]);
    }
}
