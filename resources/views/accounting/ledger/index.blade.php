{{-- Buku besar per akun: saldo awal, mutasi jurnal posted di periode, dan saldo berjalan. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $saringan = array_filter(['dari' => $filters['dari'], 'sampai' => $filters['sampai'], 'cabang' => $filters['cabang'] ?? null, 'cost_center' => $filters['cost_center'] ?? null]);
@endphp

<x-app-layout>
    <x-slot name="header">Buku Besar</x-slot>
    <x-slot name="description">Mutasi dan saldo per akun dari jurnal yang sudah diposting. Draf dan jurnal yang dibatalkan tidak dihitung.</x-slot>
    <x-slot name="actions">
        @if ($account)
            @can('accounting.trial-balance.view')
                <x-ui.button variant="ghost" icon="scale" :href="route('trial-balance.index', $saringan)">Neraca saldo</x-ui.button>
            @endcan
            @can('accounting.ledger.export')
                <x-ui.export-menu :href="route('ledger.export')" />
            @endcan
        @endif
    </x-slot>

    <x-ui.card class="mb-6">
        <x-ui.filter-bar :search="false">
            <x-ui.select name="akun" label="Akun" hide-label class="sm:min-w-[18rem]" :selected="$filters['akun'] ?? null" placeholder="Pilih akun"
                         :options="$accounts->mapWithKeys(fn ($a) => [$a->id => $a->code.' '.$a->name.($a->is_active ? '' : ' (nonaktif)')])" />
            @include('accounting.partials.ledger-filters')
        </x-ui.filter-bar>
    </x-ui.card>

    @if (! $account)
        <x-ui.card>
            <x-ui.empty-state icon="book-open" title="Pilih akun"
                              description="Pilih akun dan periode di atas untuk melihat mutasinya. Dari Neraca Saldo, klik nama akun untuk langsung membuka buku besarnya." />
        </x-ui.card>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card label="Saldo awal" :value="$rp($opening)" icon="flag" color="neutral" :hint="'Sebelum '.Format::tanggal($filters['dari'])" />
            <x-ui.stat-card label="Total debit" :value="$rp($totals['debit'])" icon="arrow-down-left" color="info" />
            <x-ui.stat-card label="Total kredit" :value="$rp($totals['credit'])" icon="arrow-up-right" color="warning" />
            <x-ui.stat-card label="Saldo akhir" :value="$rp($totals['closing'])" icon="scale" :hint="'Per '.Format::tanggal($filters['sampai']).' · saldo normal '.mb_strtolower($account->normal_balance->label())" />
        </div>

        <x-ui.table :paginator="$lines">
            <x-slot:toolbar>
                <div>
                    <h2 class="text-sm font-semibold text-slate-900"><span class="font-mono text-slate-500">{{ $account->code }}</span> {{ $account->name }}</h2>
                    <p class="text-sm text-slate-500">{{ $account->type->label() }} · {{ Format::tanggal($filters['dari']) }} s.d. {{ Format::tanggal($filters['sampai']) }}</p>
                </div>
            </x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Tanggal</x-ui.table.th>
                <x-ui.table.th>Jurnal</x-ui.table.th>
                <x-ui.table.th>Keterangan</x-ui.table.th>
                @if ($costCenters->isNotEmpty())
                    <x-ui.table.th>Cost center</x-ui.table.th>
                @endif
                <x-ui.table.th align="right">Debit</x-ui.table.th>
                <x-ui.table.th align="right">Kredit</x-ui.table.th>
                <x-ui.table.th align="right">Saldo</x-ui.table.th>
            </x-slot:head>

            @if ($lines->onFirstPage())
                <tr class="bg-slate-50">
                    <x-ui.table.td numeric muted>{{ Format::tanggal($filters['dari']) }}</x-ui.table.td>
                    <x-ui.table.td colspan="{{ $costCenters->isNotEmpty() ? 5 : 4 }}"><span class="font-medium text-slate-900">Saldo awal</span></x-ui.table.td>
                    <x-ui.table.td align="right" numeric class="font-medium">{{ $rp($opening) }}</x-ui.table.td>
                </tr>
            @endif
            @forelse ($lines as $line)
                <tr>
                    <x-ui.table.td numeric muted>{{ Format::tanggal($line->journal_date) }}</x-ui.table.td>
                    <x-ui.table.td>
                        <a href="{{ route('journals.show', $line->journal_id) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $line->journal_number }}</a>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        <p class="text-slate-800">{{ $line->journal_description }}</p>
                        @if ($line->description)
                            <p class="text-xs text-slate-500">{{ $line->description }}</p>
                        @endif
                    </x-ui.table.td>
                    @if ($costCenters->isNotEmpty())
                        <x-ui.table.td muted>{{ $line->costCenter?->code ?? '—' }}</x-ui.table.td>
                    @endif
                    <x-ui.table.td align="right" numeric>{{ (float) $line->debit > 0 ? Format::rupiah($line->debit) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ (float) $line->credit > 0 ? Format::rupiah($line->credit) : '' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric class="font-medium">{{ $rp($list->runningBalance($line)) }}</x-ui.table.td>
                </tr>
            @empty
                <x-ui.table.empty colspan="{{ $costCenters->isNotEmpty() ? 7 : 6 }}" icon="book-open" title="Tidak ada mutasi di periode ini" description="Ubah periode, cabang, atau cost center." />
            @endforelse
        </x-ui.table>
    @endif
</x-app-layout>
