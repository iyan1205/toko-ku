{{-- Saldo cuti dan riwayat pengajuan cuti/izin sendiri. Pengajuan baru memakai formulir cuti yang sudah ada. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Cuti & Izin Saya</x-slot>
    <x-slot name="description">Saldo cuti tahun {{ now()->year }} dan riwayat pengajuan Anda.</x-slot>
    <x-slot name="actions">
        @can('sdm.leaves.create')
            <x-ui.button icon="plus" :href="route('leaves.create')">Ajukan cuti / izin</x-ui.button>
        @endcan
    </x-slot>

    @include('sdm.ess.nav')

    @if ($balances->isNotEmpty())
        <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ($balances as $b)
                <x-ui.stat-card :label="$b['type']->nama" :value="$b['left'] === null ? 'Tanpa kuota' : $b['left'].' hari'" icon="calendar" :color="$b['left'] === 0 ? 'warning' : 'primary'" />
            @endforeach
        </div>
    @endif

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Pengajuan</x-ui.table.th>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>
        @forelse ($requests as $c)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('leaves.show', $c) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $c->jenisCuti?->nama ?? 'Cuti' }}</a>
                    <p class="text-xs text-slate-500">{{ $c->nomor }} · {{ $c->jumlah_hari }} hari</p>
                </x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($c->tanggal_mulai) }}{{ $c->tanggal_selesai && ! $c->tanggal_selesai->equalTo($c->tanggal_mulai) ? ' – '.Format::tanggal($c->tanggal_selesai) : '' }}</x-ui.table.td>
                <x-ui.table.td><x-ui.badge color="neutral" dot>{{ $c->status->label() }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="3" icon="calendar-days" title="Belum ada pengajuan cuti" />
        @endforelse
    </x-ui.table>

    @include('sdm.ess.spacer')
</x-app-layout>
