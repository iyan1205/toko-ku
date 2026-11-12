{{-- Pengajuan lembur sendiri: formulir dan riwayat. Disetujui atasan/SDM atau lewat alur persetujuan. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Lembur Saya</x-slot>
    <x-slot name="description">Ajukan lembur sebelum atau paling lambat 31 hari sesudahnya.</x-slot>

    @include('sdm.ess.nav')

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('ess.overtime.store') }}" class="lg:col-span-1" x-data="{ kirim: false }" x-on:submit="kirim = true">
            @csrf
            <x-ui.card title="Ajukan lembur" :description="$needsFlow ? 'Pengajuan diteruskan ke alur persetujuan lembur.' : 'Pengajuan disetujui oleh bagian SDM atau atasan Anda.'">
                <div class="grid gap-4">
                    <x-ui.input name="overtime_date" label="Tanggal" type="date" required :value="now()->format('Y-m-d')" />
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.input name="start_time" label="Mulai" type="time" required />
                        <x-ui.input name="end_time" label="Selesai" type="time" required hint="Lewat tengah malam? Isi jam selesai hari berikutnya." />
                    </div>
                    <x-ui.textarea name="reason" label="Pekerjaan / alasan" rows="3" maxlength="500" required />
                    <x-ui.button type="submit" icon="paper-airplane" class="justify-center" x-bind:disabled="kirim">Ajukan</x-ui.button>
                </div>
            </x-ui.card>
        </form>

        <x-ui.table :paginator="$items" :sticky="false" class="lg:col-span-2">
            <x-slot:toolbar><h2 class="text-sm font-semibold text-slate-900">Riwayat lembur</h2></x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Tanggal</x-ui.table.th>
                <x-ui.table.th>Jam</x-ui.table.th>
                <x-ui.table.th>Status</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>
            @forelse ($items as $o)
                <tr class="align-top">
                    <x-ui.table.td>
                        <p class="font-medium text-slate-900">{{ Format::tanggal($o->overtime_date) }}</p>
                        <p class="text-xs text-slate-500">{{ $o->number }} · {{ \Illuminate\Support\Str::limit($o->reason, 60) }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td numeric>{{ $o->timeLabel() }}<span class="block text-xs text-slate-500">{{ $o->durationLabel() }}</span></x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :color="$o->statusColor()" dot>{{ $o->statusLabel() }}</x-ui.badge>
                        @if ($o->cancel_reason && $o->is_rejected)
                            <p class="mt-1 text-xs text-slate-500">{{ $o->cancel_reason }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right">
                        @if ($o->isPending())
                            <form method="POST" action="{{ route('ess.overtime.cancel', $o->id) }}">
                                @csrf
                                <button type="submit" class="text-xs font-medium text-danger-600 hover:underline">Batalkan</button>
                            </form>
                        @endif
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="4" icon="moon" title="Belum ada pengajuan lembur" />
            @endforelse
        </x-ui.table>
    </div>

    @include('sdm.ess.spacer')
</x-app-layout>
