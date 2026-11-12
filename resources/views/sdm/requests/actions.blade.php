{{--
    Aksi SDM untuk satu pengajuan lembur/reimbursement yang menunggu: setujui / tolak langsung,
    atau tautan ke Persetujuan Saya bila pengajuan sedang di alur persetujuan.
    Variabel: $item, $routePrefix, $permission.
--}}
@if ($item->isPending())
    @if ($item->pendingApproval())
        <a href="{{ route('approvals.index') }}" class="text-xs font-medium text-primary-700 hover:underline">Di alur persetujuan</a>
    @elseif (auth()->user()->can($permission.'.approve'))
        <div class="flex justify-end gap-1">
            <form method="POST" action="{{ route($routePrefix.'.approve', $item->id) }}">
                @csrf
                <x-ui.button type="submit" size="sm" variant="secondary" icon="check">Setujui</x-ui.button>
            </form>
            <x-ui.button size="sm" variant="ghost" icon="x-mark" class="text-danger-600" x-data x-on:click="$dispatch('open-modal', 'tolak-{{ $item->id }}')">Tolak</x-ui.button>
        </div>
        <x-ui.void-dialog :name="'tolak-'.$item->id" :action="route($routePrefix.'.reject', $item->id)" :title="'Tolak '.$item->number.'?'" confirm="Ya, tolak" label="Alasan penolakan">
            Karyawan melihat alasan ini di Layanan Mandiri.
        </x-ui.void-dialog>
    @endif
@endif
