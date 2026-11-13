{{-- Koreksi Absensi (SDM): daftar, saringan, ekspor, dan keputusan langsung. Disetujui = jam diterapkan ke absensi. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Koreksi Absensi</x-slot>
    <x-slot name="description">Koreksi jam masuk/pulang yang diajukan karyawan lewat Layanan Mandiri. Saat disetujui, jam yang diminta langsung menggantikan absensi hari itu.</x-slot>
    <x-slot name="actions">
        @can($permission.'.export')
            <x-ui.export-menu :href="route($routePrefix.'.export')" />
        @endcan
    </x-slot>

    <x-ui.table :paginator="$items">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari nomor atau nama karyawan">
                <x-ui.select name="status" label="Status" hide-label :options="['submitted' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak', 'cancelled' => 'Dibatalkan']" :selected="request('status')" placeholder="Semua status" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Karyawan</x-ui.table.th>
            <x-ui.table.th>Tanggal</x-ui.table.th>
            <x-ui.table.th>Sebelumnya → diminta</x-ui.table.th>
            <x-ui.table.th>Alasan</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @forelse ($items as $item)
            <tr class="align-top">
                <x-ui.table.td>
                    <p class="font-medium text-slate-900">{{ $item->employee?->name }}</p>
                    <p class="text-xs text-slate-500">{{ $item->number }} · {{ $item->employee?->code }}</p>
                </x-ui.table.td>
                <x-ui.table.td numeric muted class="whitespace-nowrap">{{ Format::tanggal($item->attendance_date) }}</x-ui.table.td>
                <x-ui.table.td numeric class="whitespace-nowrap">
                    <span class="text-slate-500">{{ $item->originalLabel() }}</span>
                    <span class="block font-medium text-slate-900">→ {{ $item->requestedLabel() }}</span>
                </x-ui.table.td>
                <x-ui.table.td><p class="max-w-md text-slate-800">{{ $item->reason }}</p></x-ui.table.td>
                <x-ui.table.td>
                    <x-ui.badge :color="$item->statusColor()" dot>{{ $item->statusLabel() }}</x-ui.badge>
                    @if ($item->approver && ! $item->is_rejected)
                        <p class="mt-1 text-xs text-slate-500">{{ $item->approver->name }}</p>
                    @elseif ($item->cancel_reason)
                        <p class="mt-1 text-xs text-slate-500">{{ $item->cancel_reason }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right">@include('sdm.requests.actions')</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="inbox" title="Belum ada pengajuan koreksi" description="Karyawan mengajukan dari Layanan Mandiri → Absensi." />
        @endforelse
    </x-ui.table>
</x-app-layout>
