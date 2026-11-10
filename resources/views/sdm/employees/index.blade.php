{{-- Daftar karyawan: cari, filter departemen/jabatan/status, urutkan, ekspor. --}}
@use('App\Support\Format')
@use('App\Enums\EmploymentType')

<x-app-layout>
    <x-slot name="header">Karyawan</x-slot>
    <x-slot name="description">Data karyawan. Karyawan yang punya akun login otomatis terhubung ke absensi, jadwal, dan cutinya.</x-slot>
    <x-slot name="actions">
        @can('sdm.employees.export')
            <x-ui.export-menu :href="route('employees.export')" />
        @endcan
        @can('sdm.employees.create')
            <x-ui.button :href="route('employees.create')" icon="plus">Tambah karyawan</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$employees">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari kode, nama, telepon, atau email">
                <x-ui.select name="departemen" label="Departemen" hide-label :options="$departments->pluck('name', 'id')" :selected="request('departemen')" placeholder="Semua departemen" />
                <x-ui.select name="jabatan" label="Jabatan" hide-label :options="$positions->pluck('name', 'id')" :selected="request('jabatan')" placeholder="Semua jabatan" />
                <x-ui.select name="jenis" label="Status kerja" hide-label :options="collect(EmploymentType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="request('jenis')" placeholder="Semua status kerja" />
                <x-ui.select name="status" label="Status" hide-label :options="['aktif' => 'Aktif', 'nonaktif' => 'Tidak aktif']" :selected="request('status')" placeholder="Aktif & tidak aktif" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="name">Karyawan</x-ui.table.th>
            <x-ui.table.th>Departemen · Jabatan</x-ui.table.th>
            <x-ui.table.th>Status kerja</x-ui.table.th>
            <x-ui.table.th sort="join">Mulai kerja</x-ui.table.th>
            <x-ui.table.th>Akun login</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($employees as $e)
            <tr @class(['opacity-60' => ! $e->is_active])>
                <x-ui.table.td>
                    <p class="font-medium text-slate-900">{{ $e->name }}</p>
                    <p class="font-mono text-xs text-slate-500">{{ $e->code }}@if ($e->phone) · {{ $e->phone }}@endif</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-700">{{ $e->department?->name ?? '—' }}</p>
                    <p class="text-xs text-slate-500">{{ $e->position?->name ?? '—' }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <x-ui.badge color="neutral">{{ $e->employment_type->label() }}</x-ui.badge>
                    @unless ($e->is_active)
                        <x-ui.badge color="danger">Tidak aktif</x-ui.badge>
                    @endunless
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ $e->join_date ? Format::tanggal($e->join_date) : '—' }}</x-ui.table.td>
                <x-ui.table.td muted>{{ $e->user?->email ?? 'Tidak ada' }}</x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('sdm.employees.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('employees.edit', $e)" aria-label="Ubah {{ $e->name }}" />
                        @endcan
                        @can('sdm.employees.delete')
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $e->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('employees.destroy', $e)) }}, nama: {{ \Illuminate\Support\Js::from($e->name) }} })" />
                        @endcan
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="user-group" title="Belum ada karyawan yang cocok" description="Ubah filter, atau tambah karyawan baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>
