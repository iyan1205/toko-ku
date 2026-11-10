{{--
    Panel persetujuan di halaman dokumen (model dengan trait HasApproval): status pengajuan terakhir,
    tahap, riwayat, dan tombol Setujui / Tolak / Tarik pengajuan bila pengguna boleh.

    <x-ui.approval-panel :document="$order" />
--}}
@props(['document'])

@php
    $pengajuan = $document->latestApproval;
    $service = app(\App\Services\Approval\ApprovalService::class);
    $user = auth()->user();
    $riwayat = $pengajuan?->history()->with('user:id,name')->get() ?? collect();
    $disetujui = $riwayat->where('action', \App\Enums\ApprovalAction::Approved)->keyBy('step');
    $bisaBertindak = $pengajuan && $service->canAct($pengajuan, $user);
    $bisaTarik = $pengajuan?->isPending() && $pengajuan->submitted_by === $user->getKey();
    $kunci = 'persetujuan-'.($pengajuan?->getKey() ?? 0);
@endphp

@if ($pengajuan)
    <x-ui.card title="Persetujuan" {{ $attributes }}>
        <x-slot:actions>
            <x-ui.badge :color="$pengajuan->status->color()" dot>{{ $pengajuan->status->label() }}</x-ui.badge>
        </x-slot:actions>

        <p class="text-sm text-slate-500">
            Alur {{ $pengajuan->flow?->name ?? '-' }} · diajukan {{ $pengajuan->submitter?->name ?? '-' }},
            {{ \App\Support\Format::tanggal($pengajuan->submitted_at, true) }}
        </p>

        <ol class="mt-4 grid gap-3">
            @foreach ($pengajuan->steps as $tahap)
                @php
                    $keputusan = $disetujui->get($tahap['sequence']);
                    $sedang = $pengajuan->isPending() && $pengajuan->current_step === $tahap['sequence'];
                    $ditolak = $pengajuan->status === \App\Enums\ApprovalStatus::Rejected && $riwayat->last()?->step === $tahap['sequence'];
                @endphp
                <li class="flex items-start gap-3">
                    <span @class([
                        'mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold',
                        'bg-success-100 text-success-700' => $keputusan,
                        'bg-danger-100 text-danger-700' => $ditolak,
                        'bg-warning-100 text-warning-700 ring-2 ring-warning-300' => $sedang,
                        'bg-slate-100 text-slate-500' => ! $keputusan && ! $sedang && ! $ditolak,
                    ])>{{ $tahap['sequence'] }}</span>
                    <div class="min-w-0 text-sm">
                        <p class="font-medium text-slate-900">{{ $tahap['name'] }}</p>
                        <p class="text-xs text-slate-500">
                            {{ \App\Models\ApprovalRequest::approverLabel($tahap) }}
                            @if ($keputusan)
                                · disetujui {{ $keputusan->user?->name }}, {{ \App\Support\Format::tanggal($keputusan->created_at, true) }}
                            @elseif ($ditolak)
                                · ditolak {{ $riwayat->last()->user?->name }}
                            @elseif ($sedang)
                                · menunggu
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ol>

        @if ($riwayat->whereNotNull('note')->isNotEmpty())
            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Catatan</p>
                <ul class="mt-2 grid gap-2 text-sm">
                    @foreach ($riwayat->whereNotNull('note') as $r)
                        <li>
                            <span class="font-medium text-slate-900">{{ $r->user?->name }}</span>
                            <span class="text-slate-500">({{ mb_strtolower($r->action->label()) }})</span>:
                            <span class="text-slate-700">{{ $r->note }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($bisaBertindak || $bisaTarik)
            <x-slot:footer>
                <div class="flex flex-wrap items-center justify-end gap-2">
                @if ($bisaTarik)
                    <form method="POST" action="{{ route('approvals.cancel', $pengajuan) }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" size="sm" icon="arrow-uturn-left">Tarik pengajuan</x-ui.button>
                    </form>
                @endif
                @if ($bisaBertindak)
                    <x-ui.button variant="secondary" size="sm" icon="x-mark" x-data x-on:click="$dispatch('open-modal', 'tolak-{{ $kunci }}')">Tolak</x-ui.button>
                    <x-ui.button size="sm" icon="check" x-data x-on:click="$dispatch('open-modal', 'setujui-{{ $kunci }}')">Setujui</x-ui.button>
                @endif
                </div>
            </x-slot:footer>
        @endif
    </x-ui.card>

    @if ($bisaBertindak)
        @include('approvals.partials.dialogs', ['pengajuan' => $pengajuan, 'kunci' => $kunci])
    @endif
@endif
