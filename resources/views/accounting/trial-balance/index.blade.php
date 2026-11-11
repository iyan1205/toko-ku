{{-- Neraca saldo: saldo awal, mutasi, dan saldo akhir per akun, dikelompokkan per kelompok akun. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@use('App\Enums\AccountType')
@php
    $kolom = ['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'closing_debit', 'closing_credit'];
    $rp = fn (int $sen) => $sen === 0 ? '' : Format::rupiah(Uang::keDesimal($sen));
    $saringan = array_filter(['dari' => $filters['dari'], 'sampai' => $filters['sampai'], 'cabang' => $filters['cabang'] ?? null, 'cost_center' => $filters['cost_center'] ?? null]);
    $seimbang = $total['opening_debit'] === $total['opening_credit'] && $total['closing_debit'] === $total['closing_credit'] && $total['period_debit'] === $total['period_credit'];
    $th = 'px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500 whitespace-nowrap';
@endphp

<x-app-layout>
    <x-slot name="header">Neraca Saldo</x-slot>
    <x-slot name="description">Saldo semua akun dari jurnal yang sudah diposting. Tanpa saringan cost center, total debit selalu sama dengan total kredit.</x-slot>
    <x-slot name="actions">
        @can('accounting.trial-balance.export')
            <x-ui.export-menu :href="route('trial-balance.export')" />
        @endcan
    </x-slot>

    <x-ui.card class="mb-6">
        <x-ui.filter-bar :search="false">
            @include('accounting.partials.ledger-filters')
            <x-ui.select name="tipe" label="Kelompok" hide-label :options="collect(AccountType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="$filters['tipe'] ?? null" placeholder="Semua kelompok" />
            <x-ui.select name="nol" label="Akun" hide-label :options="['1' => 'Termasuk akun bersaldo nol']" :selected="$filters['nol'] ? '1' : null" placeholder="Akun bersaldo/bermutasi" />
        </x-ui.filter-bar>
    </x-ui.card>

    @if (! empty($filters['cost_center']))
        <x-ui.alert type="info" class="mb-6">Hanya baris jurnal dengan cost center terpilih yang dihitung, jadi total debit dan kredit bisa berbeda.</x-ui.alert>
    @elseif (! $seimbang)
        <x-ui.alert type="danger" class="mb-6" title="Neraca saldo tidak seimbang">Ada jurnal yang tidak seimbang di data ini. Hubungi pengembang aplikasi.</x-ui.alert>
    @endif

    <x-ui.table :sticky="false">
        <x-slot:toolbar>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm text-slate-500">{{ Format::tanggal($filters['dari']) }} s.d. {{ Format::tanggal($filters['sampai']) }}</p>
                @if (empty($filters['cost_center']))
                    <x-ui.badge :color="$seimbang ? 'success' : 'danger'" dot>{{ $seimbang ? 'Seimbang' : 'Tidak seimbang' }}</x-ui.badge>
                @endif
            </div>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Akun</x-ui.table.th>
            <th class="{{ $th }}">Saldo awal (D)</th>
            <th class="{{ $th }}">Saldo awal (K)</th>
            <th class="{{ $th }}">Mutasi debit</th>
            <th class="{{ $th }}">Mutasi kredit</th>
            <th class="{{ $th }}">Saldo akhir (D)</th>
            <th class="{{ $th }}">Saldo akhir (K)</th>
        </x-slot:head>

        @forelse ($groups as $group)
            <tr class="bg-slate-50">
                <td colspan="7" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">{{ $group['type']->label() }}</td>
            </tr>
            @foreach ($group['rows'] as $row)
                <tr>
                    <x-ui.table.td>
                        @can('accounting.ledger.view')
                            <a href="{{ route('ledger.index', ['akun' => $row['account']->id] + $saringan) }}" class="hover:text-primary-700 hover:underline">
                                <span class="font-mono text-xs text-slate-500">{{ $row['account']->code }}</span> {{ $row['account']->name }}
                            </a>
                        @else
                            <span class="font-mono text-xs text-slate-500">{{ $row['account']->code }}</span> {{ $row['account']->name }}
                        @endcan
                    </x-ui.table.td>
                    @foreach ($kolom as $k)
                        <x-ui.table.td align="right" numeric>{{ $rp($row[$k]) }}</x-ui.table.td>
                    @endforeach
                </tr>
            @endforeach
            <tr class="font-medium">
                <x-ui.table.td class="text-slate-900">Jumlah {{ mb_strtolower($group['type']->label()) }}</x-ui.table.td>
                @foreach ($kolom as $k)
                    <x-ui.table.td align="right" numeric class="text-slate-900">{{ $rp($group['total'][$k]) }}</x-ui.table.td>
                @endforeach
            </tr>
        @empty
            <x-ui.table.empty colspan="7" icon="scale" title="Belum ada saldo di periode ini" description="Jurnal yang sudah diposting muncul di sini. Ubah periode atau tampilkan akun bersaldo nol." />
        @endforelse

        @if ($groups->isNotEmpty())
            <tr class="border-t-2 border-slate-300 bg-slate-50 font-semibold">
                <x-ui.table.td class="text-slate-900">Total</x-ui.table.td>
                @foreach ($kolom as $k)
                    <x-ui.table.td align="right" numeric class="text-slate-900">{{ Format::rupiah(Uang::keDesimal($total[$k])) }}</x-ui.table.td>
                @endforeach
            </tr>
        @endif
    </x-ui.table>
</x-app-layout>
