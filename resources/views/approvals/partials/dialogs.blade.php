{{-- Dialog Setujui (catatan opsional) dan Tolak (alasan wajib) untuk satu pengajuan. --}}
<x-ui.modal :name="'setujui-'.$kunci" :title="'Setujui '.$pengajuan->title.'?'">
    <form id="form-setujui-{{ $kunci }}" method="POST" action="{{ route('approvals.approve', $pengajuan) }}" class="grid gap-4">
        @csrf
        <p class="text-sm text-slate-600">{{ $pengajuan->progressLabel() }}</p>
        <x-ui.textarea name="catatan" :id="'catatan-'.$kunci" label="Catatan" rows="2" maxlength="255" />
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" x-on:click="show = false">Kembali</x-ui.button>
        <x-ui.button type="submit" icon="check" :form="'form-setujui-'.$kunci">Ya, setujui</x-ui.button>
    </x-slot:footer>
</x-ui.modal>

<x-ui.void-dialog :name="'tolak-'.$kunci" :action="route('approvals.reject', $pengajuan)" :title="'Tolak '.$pengajuan->title.'?'"
                  confirm="Ya, tolak" label="Alasan penolakan">
    Pengajuan berhenti dan dokumen kembali menjadi draf supaya bisa diperbaiki pengaju.
</x-ui.void-dialog>
