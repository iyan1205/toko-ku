{{-- Rekening kas & bank: saldo per rekening (dari jurnal posted), kas kecil dengan pengisian ulang. --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $user = auth()->user();
@endphp

<x-app-layout>
    <x-slot name="header">Rekening Kas & Bank</x-slot>
    <x-slot name="description">Saldo kas, rekening bank, dan kas kecil dari jurnal yang sudah diposting. Setiap rekening menempel pada satu akun kas/bank di Bagan Akun.</x-slot>
    <x-slot name="actions">
        @can('accounting.cash-bank.create')
            <x-ui.button variant="secondary" icon="arrow-down-left" :href="route('cash-transactions.create', 'masuk')">Penerimaan</x-ui.button>
            <x-ui.button variant="secondary" icon="arrow-up-right" :href="route('cash-transactions.create', 'keluar')">Pengeluaran</x-ui.button>
            <x-ui.button variant="secondary" icon="arrows-right-left" :href="route('cash-transactions.create', 'transfer')">Transfer</x-ui.button>
        @endcan
        @can('accounting.cash-bank.update')
            <x-ui.button icon="plus" :href="route('cash-bank-accounts.create')">Tambah rekening</x-ui.button>
        @endcan
    </x-slot>

    @if ($unlinked->isNotEmpty())
        <x-ui.alert type="info" class="mb-6" title="Akun kas/bank tanpa rekening">
            {{ $unlinked->map->label()->join(', ') }} bertanda kas/bank di Bagan Akun tetapi belum dibuatkan rekening, jadi belum bisa dipilih di transaksi kas.
            @can('accounting.cash-bank.update')
                <a href="{{ route('cash-bank-accounts.create') }}" class="font-medium underline">Tambah rekening</a> dan pilih akunnya.
            @endcan
        </x-ui.alert>
    @endif

    @if ($rekening->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="banknotes" title="Belum ada rekening"
                              description="Rekening dibuat otomatis untuk akun Kas dan Bank dari bagan akun standar. Pasang bagan akun dulu, atau tambah rekening baru." />
        </x-ui.card>
    @else
        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <x-ui.stat-card label="Total kas" :value="$rp($rekening->filter(fn ($r) => $r->kind !== \App\Enums\CashBankKind::Bank)->sum(fn ($r) => $balances[$r->id] ?? 0))" icon="banknotes" color="success" />
            <x-ui.stat-card label="Total bank" :value="$rp($rekening->filter(fn ($r) => $r->kind === \App\Enums\CashBankKind::Bank)->sum(fn ($r) => $balances[$r->id] ?? 0))" icon="building-library" color="info" />
            <x-ui.stat-card label="Kas kecil perlu diisi" :value="$rp(collect($replenish)->sum())" icon="wallet" color="warning" :hint="collect($replenish)->filter()->count().' rekening di bawah plafon'" />
        </div>

        <x-ui.table :sticky="false">
            <x-slot:head>
                <x-ui.table.th>Rekening</x-ui.table.th>
                <x-ui.table.th>Akun</x-ui.table.th>
                <x-ui.table.th>Cabang</x-ui.table.th>
                <x-ui.table.th align="right">Saldo</x-ui.table.th>
                <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
            </x-slot:head>
            @foreach ($rekening as $r)
                <tr @class(['opacity-60' => ! $r->is_active])>
                    <x-ui.table.td>
                        <div class="flex items-center gap-3">
                            <x-ui.icon :name="$r->kind->icon()" class="h-5 w-5 text-slate-400" />
                            <div>
                                <p class="font-medium text-slate-900">{{ $r->name }}
                                    <x-ui.badge color="neutral" class="ml-1">{{ $r->kind->label() }}</x-ui.badge>
                                    @unless ($r->is_active)
                                        <x-ui.badge color="neutral" class="ml-1">Nonaktif</x-ui.badge>
                                    @endunless
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ collect([$r->bank_name, $r->account_number, $r->account_holder ? 'a.n. '.$r->account_holder : null])->filter()->join(' · ') ?: '—' }}
                                    @if ($r->isPettyCash())
                                        · plafon @rupiah($r->imprest_amount)
                                    @endif
                                </p>
                            </div>
                        </div>
                    </x-ui.table.td>
                    <x-ui.table.td muted><span class="font-mono text-xs">{{ $r->account?->code }}</span> {{ $r->account?->name }}</x-ui.table.td>
                    <x-ui.table.td muted>{{ $r->branch?->name ?? 'Semua cabang' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>
                        <span class="font-medium text-slate-900">{{ $rp($balances[$r->id] ?? 0) }}</span>
                        @if (($replenish[$r->id] ?? 0) > 0)
                            <span class="block text-xs text-warning-700">Kurang {{ $rp($replenish[$r->id]) }} dari plafon</span>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right">
                        <div class="flex items-center justify-end gap-1">
                            @if (($replenish[$r->id] ?? 0) > 0 && $user->can('accounting.cash-bank.create'))
                                <x-ui.button variant="secondary" size="sm" icon="arrow-path" :href="route('cash-transactions.create', ['jenis' => 'transfer', 'tujuan' => $r->id])">Isi ulang</x-ui.button>
                            @endif
                            @can('accounting.cash-bank.view')
                                <x-ui.button variant="ghost" size="sm" icon="list-bullet" :href="route('cash-transactions.index', ['rekening' => $r->id])" aria-label="Transaksi {{ $r->name }}" />
                            @endcan
                            @can('accounting.ledger.view')
                                <x-ui.button variant="ghost" size="sm" icon="book-open" :href="route('ledger.index', ['akun' => $r->account_id])" aria-label="Buku besar {{ $r->name }}" />
                            @endcan
                            @can('accounting.cash-bank.update')
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('cash-bank-accounts.edit', $r)" aria-label="Ubah {{ $r->name }}" />
                            @endcan
                        </div>
                    </x-ui.table.td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</x-app-layout>
