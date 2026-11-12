{{-- SDM → Offboarding: karyawan yang resign/PHK beserta progres checklist pengembaliannya. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Offboarding</x-slot>

    <x-ui.table :paginator="$offboardings">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nama atau kode karyawan">
                <x-ui.select name="status" label="Status" hide-label :options="$statuses" :selected="request('status')" placeholder="Semua status" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Karyawan</x-ui.table.th>
            <x-ui.table.th>Alasan</x-ui.table.th>
            <x-ui.table.th>Hari terakhir</x-ui.table.th>
            <x-ui.table.th>Checklist</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
        </x-slot:head>
        @forelse ($offboardings as $o)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('offboardings.show', $o) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $o->employee->name }}</a>
                    <span class="block font-mono text-xs text-slate-500">{{ $o->employee->code }}{{ $o->employee->position ? ' · '.$o->employee->position->name : '' }}</span>
                </x-ui.table.td>
                <x-ui.table.td>{{ $o->termination_type->label() }}</x-ui.table.td>
                <x-ui.table.td numeric>{{ Format::tanggal($o->last_working_date) }}</x-ui.table.td>
                <x-ui.table.td>
                    <div class="flex items-center gap-2">
                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-200"><div class="h-full bg-primary-600" style="width: {{ $o->items_count ? round($o->done_count / $o->items_count * 100) : 100 }}%"></div></div>
                        <span class="text-xs text-slate-500">{{ $o->done_count }}/{{ $o->items_count }}</span>
                    </div>
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$o->statusColor()" dot>{{ $o->statusLabel() }}</x-ui.badge></x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="arrow-right-start-on-rectangle" title="Belum ada offboarding" description="Mulai offboarding dari halaman detail karyawan." />
        @endforelse
    </x-ui.table>
</x-app-layout>
