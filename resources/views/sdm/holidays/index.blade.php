{{-- SDM → Hari Libur: libur nasional (semua cabang) dan libur khusus cabang per tahun. --}}
@use('App\Support\Format')
@php
    $user = auth()->user();
    $bolehUbah = $editing ? $user->can('sdm.holidays.update') : $user->can('sdm.holidays.create');
@endphp

<x-app-layout>
    <x-slot name="header">Hari Libur</x-slot>
    <x-slot name="description">Hari libur tidak dihitung sebagai alpha, lembur pada hari itu memakai tarif hari libur, dan cuti hari kerja tidak memotong saldo.</x-slot>
    <x-slot name="actions">
        @can('sdm.holidays.import')
            <x-ui.button variant="secondary" icon="arrow-up-tray" :href="route('imports.holidays.create')">Impor</x-ui.button>
        @endcan
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <div class="flex items-center gap-2">
                        <x-ui.button variant="ghost" size="sm" icon="chevron-left" :href="route('holidays.index', ['tahun' => $year - 1])" aria-label="Tahun sebelumnya" />
                        <h2 class="text-sm font-semibold text-slate-900">Tahun {{ $year }}</h2>
                        <x-ui.button variant="ghost" size="sm" icon="chevron-right" :href="route('holidays.index', ['tahun' => $year + 1])" aria-label="Tahun berikutnya" />
                    </div>
                    <p class="text-sm text-slate-500">{{ $holidays->count() }} hari libur</p>
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Tanggal</x-ui.table.th>
                    <x-ui.table.th>Nama</x-ui.table.th>
                    <x-ui.table.th>Berlaku</x-ui.table.th>
                    <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                </x-slot:head>
                @forelse ($holidays as $h)
                    <tr @class(['bg-primary-50/50' => $editing?->id === $h->id])>
                        <x-ui.table.td class="whitespace-nowrap">
                            {{ Format::tanggal($h->date) }}
                            <span class="block text-xs text-slate-500">{{ $h->date->locale('id')->translatedFormat('l') }}</span>
                        </x-ui.table.td>
                        <x-ui.table.td class="font-medium text-slate-900">{{ $h->name }}</x-ui.table.td>
                        <x-ui.table.td>
                            @if ($h->branch_id)
                                <x-ui.badge color="info">{{ $h->branch?->name }}</x-ui.badge>
                            @else
                                <x-ui.badge>Nasional</x-ui.badge>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right" class="whitespace-nowrap">
                            @can('sdm.holidays.update')
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('holidays.index', ['tahun' => $year, 'ubah' => $h->id])" aria-label="Ubah {{ $h->name }}" />
                            @endcan
                            @can('sdm.holidays.delete')
                                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus {{ $h->name }}" x-data
                                    x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('holidays.destroy', $h)) }}, nama: {{ \Illuminate\Support\Js::from($h->name.' '.Format::tanggal($h->date)) }} })" />
                            @endcan
                        </x-ui.table.td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4"><x-ui.empty-state icon="calendar-days" title="Belum ada hari libur tahun {{ $year }}" description="Tambahkan satu per satu atau impor dari Excel (kolom Tanggal dan Nama)." /></td>
                    </tr>
                @endforelse
            </x-ui.table>
        </div>

        @if ($bolehUbah)
            <form method="POST" action="{{ $editing ? route('holidays.update', $editing) : route('holidays.store') }}">
                @csrf
                @if ($editing)
                    @method('PUT')
                @endif
                <x-ui.form-section :title="$editing ? 'Ubah hari libur' : 'Tambah hari libur'" description="Pilih cabang hanya untuk libur yang berlaku di satu cabang saja (misal ulang tahun kota).">
                    <x-ui.input name="date" label="Tanggal" type="date" required :value="$editing?->date->toDateString() ?? ($year === (int) today()->year ? today()->toDateString() : $year.'-01-01')" />
                    <x-ui.input name="name" label="Nama" required maxlength="100" :value="$editing?->name" placeholder="Misal Hari Raya Idulfitri" />
                    <x-ui.select name="branch_id" label="Berlaku untuk" :options="$branches" :selected="$editing?->branch_id" placeholder="Semua cabang (nasional)" />
                    <x-slot:footer>
                        @if ($editing)
                            <x-ui.button variant="secondary" :href="route('holidays.index', ['tahun' => $year])">Batal</x-ui.button>
                        @endif
                        <x-ui.button type="submit">{{ $editing ? 'Simpan perubahan' : 'Tambah' }}</x-ui.button>
                    </x-slot:footer>
                </x-ui.form-section>
            </form>
        @endif
    </div>

</x-app-layout>
