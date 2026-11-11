{{-- Giro & cek mundur: yang diterima dari pelanggan dan yang diberikan ke supplier. --}}
@use('App\Support\Format')
@use('App\Enums\GiroStatus')
@use('App\Enums\SettlementType')
@php
    $user = auth()->user();
@endphp

<x-app-layout>
    <x-slot name="header">Giro & Cek</x-slot>
    <x-slot name="description">Giro/cek mundur dari pelunasan piutang dan pembayaran hutang. Saat cair, uangnya masuk/keluar rekening; bila ditolak, pelunasannya dibatalkan dan sisa faktur kembali.</x-slot>

    <nav class="mb-4 flex gap-6 border-b border-slate-200" aria-label="Status giro">
        @foreach (GiroStatus::cases() as $s)
            <a href="{{ route('giros.index', array_filter(['status' => $s->value, 'arah' => request('arah')])) }}" @class([
                '-mb-px whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium',
                'border-primary-600 text-primary-700' => $status === $s,
                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $status !== $s,
            ]) @if ($status === $s) aria-current="page" @endif>{{ $s->label() }}</a>
        @endforeach
    </nav>

    <x-ui.table :paginator="$giros">
        <x-slot:toolbar>
            <x-ui.filter-bar :search="false">
                <input type="hidden" name="status" value="{{ $status->value }}">
                <x-ui.select name="arah" label="Arah" hide-label :options="['masuk' => 'Dari pelanggan', 'keluar' => 'Ke supplier']" :selected="request('arah')" placeholder="Masuk & keluar" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>No. giro/cek</x-ui.table.th>
            <x-ui.table.th>Pihak</x-ui.table.th>
            <x-ui.table.th>Jatuh tempo</x-ui.table.th>
            <x-ui.table.th>Pelunasan</x-ui.table.th>
            <x-ui.table.th align="right">Nilai</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @forelse ($giros as $g)
            @php
                $masuk = $g->type === SettlementType::Receipt;
                $lewat = $g->giro_due_date && $g->giro_due_date->lt(today());
            @endphp
            <tr>
                <x-ui.table.td>
                    <p class="font-medium text-slate-900">{{ $g->giro_number }}</p>
                    <p class="text-xs text-slate-500">{{ $g->giro_bank ?? '—' }} · {{ $masuk ? 'Diterima' : 'Diberikan' }}</p>
                </x-ui.table.td>
                <x-ui.table.td>{{ $g->party()?->name }}</x-ui.table.td>
                <x-ui.table.td numeric :class="$lewat && $status === GiroStatus::Pending ? 'text-danger-700' : ''">
                    {{ Format::tanggal($g->giro_due_date) }}
                    @if ($g->giro_cleared_on)
                        <span class="block text-xs text-slate-500">Cair {{ Format::tanggal($g->giro_cleared_on) }} · {{ $g->giroCashBankAccount?->name }}</span>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td><a href="{{ route($g->type->routePrefix().'.show', $g) }}" class="text-primary-700 hover:underline">{{ $g->number }}</a></x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($g->amount)</x-ui.table.td>
                <x-ui.table.td align="right">
                    @if ($status === GiroStatus::Pending)
                        <div class="flex justify-end gap-1">
                            @if ($user->can($g->type->permission('create')))
                                <x-ui.button size="sm" variant="secondary" icon="check" x-data x-on:click="$dispatch('open-modal', 'cair-{{ $g->id }}')">Cair</x-ui.button>
                            @endif
                            @if ($user->can($g->type->permission('void')))
                                <x-ui.button size="sm" variant="ghost" icon="x-mark" class="text-danger-600" x-data x-on:click="$dispatch('open-modal', 'tolak-{{ $g->id }}')">Tolak</x-ui.button>
                            @endif
                        </div>
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="document-duplicate" :title="'Tidak ada giro '.mb_strtolower($status->label())" description="Giro/cek muncul di sini saat pelunasan piutang atau pembayaran hutang memakai cara bayar Giro/cek." />
        @endforelse
    </x-ui.table>

    @if ($status === GiroStatus::Pending)
        @foreach ($giros as $g)
            @if ($user->can($g->type->permission('create')))
                <x-ui.modal :name="'cair-'.$g->id" :title="'Giro '.$g->giro_number.' cair'" :description="'Nilai '.Format::rupiah($g->amount).' '.($g->type === SettlementType::Receipt ? 'masuk ke' : 'keluar dari').' rekening yang dipilih.'">
                    <form method="POST" action="{{ route('giros.clear', $g) }}" id="form-cair-{{ $g->id }}" class="grid gap-4">
                        @csrf
                        <x-ui.select name="cash_bank_account_id" label="Rekening" required :options="$rekening->pluck('name', 'id')" placeholder="Pilih rekening" />
                        <x-ui.input name="tanggal" label="Tanggal cair" type="date" required :value="now()->format('Y-m-d')" :max="now()->format('Y-m-d')" />
                    </form>
                    <x-slot:footer>
                        <x-ui.button variant="secondary" x-on:click="show = false">Batal</x-ui.button>
                        <x-ui.button type="submit" :form="'form-cair-'.$g->id">Catat cair</x-ui.button>
                    </x-slot:footer>
                </x-ui.modal>
            @endif
            @if ($user->can($g->type->permission('void')))
                <x-ui.void-dialog :name="'tolak-'.$g->id" :action="route('giros.bounce', $g)" :title="'Giro '.$g->giro_number.' ditolak?'" confirm="Ya, ditolak" label="Alasan penolakan">
                    Pelunasan {{ $g->number }} dibatalkan: jurnalnya dibalik dan sisa faktur yang dilunasinya kembali.
                </x-ui.void-dialog>
            @endif
        @endforeach
    @endif
</x-app-layout>
