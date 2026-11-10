{{-- Pengaturan → Alur Persetujuan: daftar alur per jenis dokumen beserta kondisi dan tahapnya. --}}
<x-app-layout>
    <x-slot name="header">Alur Persetujuan</x-slot>
    @can('settings.approvals.create')
        <x-slot name="actions">
            <x-ui.button :href="route('settings.approval-flows.create')" icon="plus">Tambah alur</x-ui.button>
        </x-slot>
    @endcan

    <x-ui.alert class="mb-6">
        Dokumen yang cocok dengan alur aktif tidak bisa disetujui langsung: harus diajukan, lalu disetujui berurutan per tahap.
        Bila beberapa alur cocok, dipakai prioritas terkecil. Dokumen yang tidak cocok dengan alur mana pun tetap disetujui seperti biasa.
    </x-ui.alert>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Alur</x-ui.table.th>
            <x-ui.table.th>Berlaku untuk</x-ui.table.th>
            <x-ui.table.th>Tahap</x-ui.table.th>
            <x-ui.table.th>Status</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($flows as $flow)
            <tr>
                <x-ui.table.td>
                    <p class="font-medium text-slate-900">{{ $flow->name }}</p>
                    <p class="text-xs text-slate-500">{{ $flow->documentLabel() }} · prioritas {{ $flow->priority }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-800">{{ $flow->conditionSummary() }}</p>
                    <p class="text-xs text-slate-500">{{ $flow->branch?->name ?? 'Semua cabang' }}</p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <ol class="grid gap-0.5 text-sm">
                        @foreach ($flow->steps as $step)
                            <li>
                                <span class="text-slate-400">{{ $step->sequence }}.</span> {{ $step->name }}
                                <span @class(['text-xs', 'text-slate-500' => $step->approverLabel(), 'font-medium text-danger-600' => ! $step->approverLabel()])>({{ $step->approverLabel() ?? 'penyetuju sudah dihapus' }})</span>
                            </li>
                        @endforeach
                    </ol>
                </x-ui.table.td>
                <x-ui.table.td>
                    @if ($flow->is_active)
                        <x-ui.badge color="success" dot>Aktif</x-ui.badge>
                    @else
                        <x-ui.badge dot>Nonaktif</x-ui.badge>
                    @endif
                    @if ($flow->pending_count > 0)
                        <p class="mt-1 text-xs text-warning-700">{{ $flow->pending_count }} pengajuan berjalan</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('settings.approvals.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('settings.approval-flows.edit', $flow)" aria-label="Ubah {{ $flow->name }}" />
                        @endcan
                        @can('settings.approvals.delete')
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $flow->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('settings.approval-flows.destroy', $flow)) }}, nama: {{ \Illuminate\Support\Js::from('Alur '.$flow->name) }}, pesan: 'akan dihapus. Pengajuan yang sedang berjalan tetap dilanjutkan dengan tahap lamanya.' })" />
                        @endcan
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="clipboard-document-check" title="Belum ada alur persetujuan"
                              description="Contoh: purchase order dengan total lebih dari Rp 5.000.000 disetujui Admin, lalu Pemilik." />
        @endforelse
    </x-ui.table>
</x-app-layout>
