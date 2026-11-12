{{-- Satu offboarding: data berhenti, checklist pengembalian, selesai (nonaktifkan karyawan & akun) atau batal. --}}
@use('App\Support\Format')
@php
    $o = $offboarding;
    $e = $o->employee;
    $bolehIsi = $o->isInProgress() && auth()->user()->can('sdm.offboarding.create');
    $bolehSelesai = $o->isInProgress() && auth()->user()->can('sdm.offboarding.approve');
    $sisa = $o->items->where('is_done', false)->count();
@endphp

<x-app-layout>
    <x-slot name="header">Offboarding {{ $e->name }}</x-slot>
    <x-slot name="description">{{ $e->code }} · {{ $o->termination_type->label() }} · hari terakhir {{ Format::tanggal($o->last_working_date) }}</x-slot>
    <x-slot name="actions">
        @can('sdm.employees.view')
            <x-ui.button variant="secondary" icon="user" :href="route('employees.show', $e)">Data karyawan</x-ui.button>
        @endcan
        @if ($bolehSelesai)
            <x-ui.button variant="ghost" class="text-danger-600" icon="x-mark" x-data x-on:click="$dispatch('open-modal', 'batal-offboarding')">Batalkan</x-ui.button>
        @endif
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Checklist pengembalian" :description="$sisa > 0 ? $sisa.' dari '.$o->items->count().' belum selesai.' : 'Semua sudah selesai.'">
            <ul class="divide-y divide-slate-100">
                @foreach ($o->items as $item)
                    <li class="flex flex-wrap items-center gap-3 py-3">
                        @if ($bolehIsi)
                            <form method="POST" action="{{ route('offboardings.items.toggle', [$o, $item]) }}">
                                @csrf
                                <input type="hidden" name="done" value="{{ $item->is_done ? 0 : 1 }}">
                                <button type="submit" class="flex h-6 w-6 items-center justify-center rounded border {{ $item->is_done ? 'border-success-600 bg-success-600 text-white' : 'border-slate-300 bg-white hover:border-primary-500' }}"
                                        aria-label="{{ $item->is_done ? 'Tandai belum: ' : 'Tandai selesai: ' }}{{ $item->label }}">
                                    @if ($item->is_done)<x-ui.icon name="check" variant="m" class="h-4 w-4" />@endif
                                </button>
                            </form>
                        @else
                            <x-ui.icon :name="$item->is_done ? 'check-circle' : 'minus-circle'" variant="s" class="h-6 w-6 {{ $item->is_done ? 'text-success-600' : 'text-slate-300' }}" />
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium {{ $item->is_done ? 'text-slate-500 line-through' : 'text-slate-900' }}">{{ $item->label }}</p>
                            @if ($item->is_done)
                                <p class="text-xs text-slate-500">{{ Format::tanggal($item->done_at, true) }} oleh {{ $item->doneBy?->name ?? '-' }}{{ $item->note ? ' · '.$item->note : '' }}</p>
                            @elseif ($item->fixed_asset_id)
                                <p class="text-xs text-slate-500">Aset tetap; setelah selesai, penanggung jawabnya dikosongkan.</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($bolehIsi)
                <form method="POST" action="{{ route('offboardings.items.store', $o) }}" class="mt-3 flex gap-2 border-t border-slate-100 pt-3">
                    @csrf
                    <x-ui.input name="label" label="Tambah barang/tugas" hide-label placeholder="Tambah barang atau tugas lain" maxlength="150" class="flex-1" />
                    <x-ui.button type="submit" variant="secondary" icon="plus">Tambah</x-ui.button>
                </form>
            @endif
        </x-ui.card>

        <x-ui.card title="Ringkasan">
            <dl class="grid gap-3 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Status</dt><dd><x-ui.badge :color="$o->statusColor()" dot>{{ $o->statusLabel() }}</x-ui.badge></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Pemberitahuan</dt><dd class="font-medium">{{ Format::tanggal($o->notice_date) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Hari terakhir</dt><dd class="font-medium">{{ Format::tanggal($o->last_working_date) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-slate-500">Akun login</dt><dd class="text-right font-medium">{{ $e->user?->email ?? 'Tidak ada' }}
                    @if ($e->user)<span class="block text-xs font-normal {{ $e->user->is_active ? 'text-slate-500' : 'text-danger-600' }}">{{ $e->user->is_active ? ($o->deactivate_user ? 'Dinonaktifkan setelah hari terakhir' : 'Tetap aktif') : 'Sudah dinonaktifkan' }}</span>@endif
                </dd></div>
                @if ($o->reason)
                    <div><dt class="text-slate-500">Keterangan</dt><dd class="mt-0.5">{{ $o->reason }}</dd></div>
                @endif
                <div class="text-xs text-slate-500">Dimulai {{ Format::tanggal($o->created_at, true) }} oleh {{ $o->creator?->name ?? '-' }}</div>
                @if ($o->completed_at)
                    <div class="text-xs text-slate-500">Selesai {{ Format::tanggal($o->completed_at, true) }} oleh {{ $o->completedBy?->name ?? '-' }}{{ $o->deactivated_at ? '; dinonaktifkan '.Format::tanggal($o->deactivated_at, true) : '' }}</div>
                @endif
                @if ($o->cancelled_at)
                    <div class="text-xs text-slate-500">Dibatalkan {{ Format::tanggal($o->cancelled_at, true) }} oleh {{ $o->cancelledBy?->name ?? '-' }}: {{ $o->cancel_reason }}</div>
                @endif
            </dl>
            @if ($bolehSelesai)
                <form method="POST" action="{{ route('offboardings.complete', $o) }}" class="mt-5 border-t border-slate-100 pt-4">
                    @csrf
                    <x-ui.button type="submit" icon="check" class="w-full justify-center" :disabled="$sisa > 0">Selesaikan offboarding</x-ui.button>
                    <p class="mt-2 text-xs text-slate-500">{{ $sisa > 0 ? 'Selesaikan semua checklist dulu.' : 'Tanggal dan alasan berhenti dicatat; karyawan'.($o->deactivate_user && $e->user ? ' dan akun loginnya' : '').' dinonaktifkan pada hari terakhir kerja.' }}</p>
                </form>
            @endif
        </x-ui.card>
    </div>

    @if ($bolehSelesai)
        <x-ui.void-dialog name="batal-offboarding" :action="route('offboardings.cancel', $o)" title="Batalkan offboarding?" confirm="Ya, batalkan">
            Karyawan tetap aktif dan checklist ini ditutup.
        </x-ui.void-dialog>
    @endif
</x-app-layout>
