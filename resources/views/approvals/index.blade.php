{{--
    Persetujuan Saya: dokumen yang menunggu persetujuan saya (bisa langsung disetujui/ditolak),
    pengajuan saya, dan riwayat keputusan saya.
--}}
@use('App\Http\Controllers\Approval\ApprovalController')
@use('App\Enums\ApprovalStatus')
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Persetujuan Saya</x-slot>
    <x-slot name="description">Dokumen yang menunggu persetujuan Anda, pengajuan Anda, dan keputusan yang sudah Anda buat.</x-slot>

    <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200" aria-label="Bagian persetujuan">
        @foreach (ApprovalController::TABS as $kunci => $label)
            <a href="{{ route('approvals.index', ['tab' => $kunci]) }}" @class([
                '-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium',
                'border-primary-600 text-primary-700' => $tab === $kunci,
                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $tab !== $kunci,
            ]) @if ($tab === $kunci) aria-current="page" @endif>
                {{ $label }}
                @if ($kunci === 'menunggu' && $waitingCount > 0)
                    <span class="rounded-full bg-warning-100 px-2 py-0.5 text-xs font-semibold text-warning-700">{{ $waitingCount }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    @if ($tab === 'menunggu')
        <x-ui.table :paginator="$items">
            <x-slot:head>
                <x-ui.table.th>Dokumen</x-ui.table.th>
                <x-ui.table.th>Tahap</x-ui.table.th>
                <x-ui.table.th>Diajukan</x-ui.table.th>
                <x-ui.table.th align="right">Nilai</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>
            @forelse ($items as $p)
                @php($kunci = 'persetujuan-'.$p->id)
                <tr>
                    <x-ui.table.td>
                        @if ($p->approvable)
                            <a href="{{ $p->approvable->approvalUrl() }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->title }}</a>
                        @else
                            <span class="font-medium text-slate-900">{{ $p->title }}</span>
                        @endif
                        <p class="text-xs text-slate-500">{{ $p->documentLabel() }}{{ $p->branch ? ' · '.$p->branch->name : '' }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td muted>{{ $p->progressLabel() }}</x-ui.table.td>
                    <x-ui.table.td muted>
                        {{ $p->submitter?->name }}
                        <span class="block text-xs">{{ Format::tanggal($p->submitted_at, true) }}</span>
                        @if ($p->note)
                            <span class="block text-xs italic">“{{ $p->note }}”</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $p->amount !== null ? Format::rupiah($p->amount) : '—' }}</x-ui.table.td>
                    <x-ui.table.td align="right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button variant="secondary" size="sm" x-data x-on:click="$dispatch('open-modal', 'tolak-{{ $kunci }}')">Tolak</x-ui.button>
                            <x-ui.button size="sm" icon="check" x-data x-on:click="$dispatch('open-modal', 'setujui-{{ $kunci }}')">Setujui</x-ui.button>
                        </div>
                        @include('approvals.partials.dialogs', ['pengajuan' => $p, 'kunci' => $kunci])
                    </x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="5" icon="check-badge" title="Tidak ada yang menunggu persetujuan Anda"
                                  description="Dokumen yang perlu Anda setujui akan muncul di sini, dan Anda juga diberi notifikasi." />
            @endforelse
        </x-ui.table>
    @elseif ($tab === 'pengajuan')
        <x-ui.table :paginator="$items">
            <x-slot:toolbar>
                <x-ui.filter-bar :search="null">
                    <input type="hidden" name="tab" value="pengajuan">
                    <x-ui.select name="status" label="Status" hide-label :options="collect(ApprovalStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :selected="request('status')" placeholder="Semua status" />
                </x-ui.filter-bar>
            </x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Dokumen</x-ui.table.th>
                <x-ui.table.th>Status</x-ui.table.th>
                <x-ui.table.th>Diajukan</x-ui.table.th>
                <x-ui.table.th align="right">Nilai</x-ui.table.th>
            </x-slot:head>
            @forelse ($items as $p)
                <tr>
                    <x-ui.table.td>
                        @if ($p->approvable)
                            <a href="{{ $p->approvable->approvalUrl() }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $p->title }}</a>
                        @else
                            <span class="font-medium text-slate-900">{{ $p->title }}</span>
                        @endif
                        <p class="text-xs text-slate-500">{{ $p->documentLabel() }}{{ $p->branch ? ' · '.$p->branch->name : '' }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :color="$p->status->color()" dot>{{ $p->status->label() }}</x-ui.badge>
                        @if ($p->isPending())
                            <p class="mt-1 text-xs text-slate-500">{{ $p->progressLabel() }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td muted>{{ Format::tanggal($p->submitted_at, true) }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $p->amount !== null ? Format::rupiah($p->amount) : '—' }}</x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="4" icon="paper-airplane" title="Belum ada pengajuan" description="Dokumen yang Anda ajukan untuk disetujui tampil di sini." />
            @endforelse
        </x-ui.table>
    @else
        <x-ui.table :paginator="$items">
            <x-slot:head>
                <x-ui.table.th>Dokumen</x-ui.table.th>
                <x-ui.table.th>Keputusan</x-ui.table.th>
                <x-ui.table.th>Tahap</x-ui.table.th>
                <x-ui.table.th>Waktu</x-ui.table.th>
            </x-slot:head>
            @forelse ($items as $a)
                <tr>
                    <x-ui.table.td>
                        @if ($a->request?->approvable)
                            <a href="{{ $a->request->approvable->approvalUrl() }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $a->request->title }}</a>
                        @else
                            <span class="font-medium text-slate-900">{{ $a->request?->title }}</span>
                        @endif
                        <p class="text-xs text-slate-500">Diajukan {{ $a->request?->submitter?->name }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <x-ui.badge :color="$a->action->color()">{{ $a->action->label() }}</x-ui.badge>
                        @if ($a->note)
                            <p class="mt-1 text-xs text-slate-500">{{ $a->note }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td muted>{{ $a->step_name ?? '—' }}</x-ui.table.td>
                    <x-ui.table.td muted>{{ Format::tanggal($a->created_at, true) }}</x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="4" icon="clock" title="Belum ada keputusan" description="Persetujuan dan penolakan yang Anda buat tampil di sini." />
            @endforelse
        </x-ui.table>
    @endif
</x-app-layout>
