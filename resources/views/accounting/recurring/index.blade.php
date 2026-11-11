{{-- Daftar templat jurnal berulang. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Jurnal Berulang</x-slot>
    <x-slot name="description">Templat jurnal yang dibuat otomatis setiap periode, misal sewa, cicilan, atau penyusutan bulanan.</x-slot>
    <x-slot name="actions">
        @can('accounting.recurring.create')
            <x-ui.button :href="route('recurring-journals.create')" icon="plus">Jurnal berulang baru</x-ui.button>
        @endcan
    </x-slot>

    @unless ($schedulerAlive)
        <x-ui.alert type="warning" class="mb-6" title="Scheduler belum berjalan">
            Jurnal berulang dibuat otomatis oleh scheduler. Sampai scheduler dipasang di server (docs/DEPLOY.md), buka templatnya lalu klik "Buat jurnal sekarang".
        </x-ui.alert>
    @endunless

    <x-ui.table :paginator="$templates">
        <x-slot:head>
            <x-ui.table.th>Templat</x-ui.table.th>
            <x-ui.table.th>Jadwal</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right">Nilai</x-ui.table.th>
            <x-ui.table.th align="right">Jurnal dibuat</x-ui.table.th>
        </x-slot:head>
        @forelse ($templates as $t)
            <tr @class(['opacity-60' => ! $t->is_active])>
                <x-ui.table.td>
                    <a href="{{ route('recurring-journals.show', $t) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $t->name }}</a>
                    <p class="text-xs text-slate-500">{{ $t->description }}{{ $t->auto_post ? '' : ' · dibuat sebagai draf' }}</p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $t->scheduleLabel() }}</x-ui.table.td>
                <x-ui.table.td>
                    @if ($t->last_error)
                        <x-ui.badge color="danger" dot>Gagal</x-ui.badge>
                    @else
                        <x-ui.badge :color="$t->is_active && $t->next_run_on ? 'success' : 'neutral'" dot>{{ $t->statusLabel() }}</x-ui.badge>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($t->total())</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $t->journals_count }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="arrow-path" title="Belum ada jurnal berulang" description="Buat templat untuk jurnal yang sama setiap bulan, misal sewa atau penyusutan." />
        @endforelse
    </x-ui.table>
</x-app-layout>
