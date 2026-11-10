{{-- Ekspor Saya: berkas ekspor data besar yang dibuat di latar belakang (queue). --}}
@use('App\Support\Format')
@use('App\Models\ExportFile')

<x-app-layout>
    <x-slot name="header">Ekspor Saya</x-slot>
    <x-slot name="description">Ekspor data besar dibuat di latar belakang. Berkas bisa diunduh selama {{ ExportFile::KEEP_DAYS }} hari.</x-slot>

    <x-ui.table :paginator="$exports">
        <x-slot:head>
            <x-ui.table.th>Data</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th>Diminta</x-ui.table.th>
            <x-ui.table.th align="right">Baris</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Unduh</span></x-ui.table.th>
        </x-slot:head>
        @forelse ($exports as $e)
            <tr>
                <x-ui.table.td>
                    <p class="font-medium text-slate-900">{{ $e->title }}</p>
                    <p class="text-xs text-slate-500">{{ $e->file_name }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <x-ui.badge :color="$e->statusColor()" dot>{{ $e->statusLabel() }}</x-ui.badge>
                    @if ($e->status === ExportFile::FAILED && $e->error)
                        <p class="mt-1 max-w-xs truncate text-xs text-danger-600" title="{{ $e->error }}">{{ $e->error }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td muted>{{ Format::tanggal($e->created_at, true) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $e->row_count !== null ? Format::jumlah($e->row_count) : '—' }}</x-ui.table.td>
                <x-ui.table.td align="right">
                    @if ($e->isReady())
                        <x-ui.button size="sm" variant="secondary" icon="arrow-down-tray" :href="route('exports.download', $e)">Unduh</x-ui.button>
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="document-arrow-down" title="Belum ada ekspor besar"
                              description="Ekspor lebih dari {{ Format::jumlah(\App\Exports\ListExport::QUEUE_THRESHOLD) }} baris dibuat di latar belakang dan muncul di sini." />
        @endforelse
    </x-ui.table>
</x-app-layout>
