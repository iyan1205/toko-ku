{{-- Beranda Layanan Mandiri: absen hari ini, saldo cuti, pengajuan berjalan, dan pengajuan terbaru. --}}
@use('App\Support\Format')
@php
    $user = auth()->user();
@endphp

<x-app-layout>
    <x-slot name="header">Halo, {{ $employee->name }}</x-slot>
    <x-slot name="description">{{ collect([$employee->position?->name, $employee->department?->name, $employee->branch?->name])->filter()->join(' · ') ?: $employee->code }}</x-slot>

    @include('sdm.ess.nav')

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Absensi hari ini" class="lg:col-span-2">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="grid grid-cols-2 gap-6 text-sm">
                    <div><p class="text-slate-500">Masuk</p><p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $today?->clock_in?->format('H:i') ?? '—' }}</p></div>
                    <div><p class="text-slate-500">Pulang</p><p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $today?->clock_out?->format('H:i') ?? '—' }}</p></div>
                </div>
                @can('sdm.attendances.record')
                    <div class="shrink-0">
                        @if ($today === null)
                            @include('absensi.partials.tombol-absen', ['aksi' => 'masuk', 'aturan' => $attendanceRules, 'kembali' => 'ess'])
                        @elseif ($today->clock_in !== null && $today->clock_out === null)
                            @include('absensi.partials.tombol-absen', ['aksi' => 'pulang', 'aturan' => $attendanceRules, 'kembali' => 'ess'])
                        @else
                            <x-ui.badge color="success" dot>{{ $today->clock_out ? 'Absensi hari ini lengkap' : $today->status->label() }}</x-ui.badge>
                        @endif
                    </div>
                @endcan
            </div>
            @error('lokasi')
                <p class="mt-2 text-sm text-warning-700">{{ $message }}</p>
            @enderror
            @error('foto')
                <p class="mt-2 text-sm text-warning-700">{{ $message }}</p>
            @enderror
        </x-ui.card>

        <x-ui.card title="Pengajuan berjalan">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500">Lembur menunggu</dt><dd class="font-medium text-slate-900">{{ $pendingOvertime }}</dd></div>
                <div class="flex justify-between"><dt class="text-slate-500">Reimbursement menunggu</dt><dd class="font-medium text-slate-900">{{ $pendingReimbursement }}</dd></div>
            </dl>
            <div class="mt-4 grid gap-2">
                @can('sdm.leaves.create')
                    <x-ui.button variant="secondary" icon="calendar-days" :href="route('leaves.create')" class="justify-center">Ajukan cuti / izin</x-ui.button>
                @endcan
                @can('sdm.overtime.create')
                    <x-ui.button variant="secondary" icon="moon" :href="route('ess.overtime')" class="justify-center">Ajukan lembur</x-ui.button>
                @endcan
                @can('sdm.reimbursements.create')
                    <x-ui.button variant="secondary" icon="receipt-percent" :href="route('ess.reimbursements')" class="justify-center">Ajukan reimbursement</x-ui.button>
                @endcan
            </div>
        </x-ui.card>
    </div>

    @if ($balances->isNotEmpty())
        <h2 class="mb-3 mt-8 text-sm font-semibold text-slate-900">Saldo cuti {{ now()->year }}</h2>
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($balances as $b)
                <x-ui.stat-card :label="$b['type']->nama" :value="$b['left'] === null ? 'Tanpa kuota' : $b['left'].' hari'" icon="calendar" :color="$b['left'] === 0 ? 'warning' : 'primary'" />
            @endforeach
        </div>
    @endif

    <x-ui.table :sticky="false" class="mt-8">
        <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Pengajuan terbaru</h2></x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Pengajuan</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>
        @forelse ($recent as $r)
            <tr>
                <x-ui.table.td>
                    <a href="{{ $r['url'] }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $r['kind'] }}</a>
                    <p class="text-xs text-slate-500">{{ $r['title'] }}</p>
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$r['color']" dot>{{ $r['status'] }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="2" icon="inbox" title="Belum ada pengajuan" description="Pengajuan cuti, lembur, dan reimbursement Anda muncul di sini." />
        @endforelse
    </x-ui.table>

    @include('sdm.ess.spacer')
</x-app-layout>
