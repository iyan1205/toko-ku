{{-- Bagan akun (COA): daftar bertingkat dengan saldo, filter kelompok/status, ekspor. --}}
@use('App\Support\Format')
@use('App\Enums\AccountType')
@use('App\Exports\Accounting\AccountsExport')

<x-app-layout>
    <x-slot name="header">Bagan Akun</x-slot>
    <x-slot name="description">Daftar akun untuk jurnal. Akun sistem dipakai jurnal otomatis dari penjualan dan pembelian.</x-slot>
    <x-slot name="actions">
        @can('accounting.coa.export')
            <x-ui.export-menu :href="route('accounts.export')" />
        @endcan
        @can('accounting.coa.create')
            <x-ui.button :href="route('accounts.create')" icon="plus">Tambah akun</x-ui.button>
        @endcan
    </x-slot>

    @unless ($installed)
        <x-ui.card class="mb-6">
            <x-ui.empty-state icon="calculator" title="Bagan akun belum dipasang"
                              description="Pasang bagan akun standar toko retail Indonesia (43 akun). Setelah itu jurnal otomatis dari penjualan dan pembelian mulai berjalan." />
            @can('accounting.coa.create')
                <form method="POST" action="{{ route('accounts.install') }}" class="-mt-6 flex justify-center pb-6">
                    @csrf
                    <x-ui.button type="submit" icon="sparkles">Pasang COA standar</x-ui.button>
                </form>
            @endcan
        </x-ui.card>
    @endunless

    <x-ui.table :paginator="$accounts">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari kode atau nama akun">
                <x-ui.select name="tipe" label="Kelompok" hide-label :options="collect(AccountType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="request('tipe')" placeholder="Semua kelompok" />
                <x-ui.select name="status" label="Status" hide-label :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" :selected="request('status')" placeholder="Aktif & nonaktif" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="code">Kode</x-ui.table.th>
            <x-ui.table.th sort="name">Nama akun</x-ui.table.th>
            <x-ui.table.th>Kelompok</x-ui.table.th>
            <x-ui.table.th>Saldo normal</x-ui.table.th>
            <x-ui.table.th align="right">Saldo</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($accounts as $a)
            @php
                // Tingkat dari rantai akun induk: kelompok utama 0, sub-kelompok 1, akun di bawahnya 2.
                $level = $a->parent_id === null ? 0 : ($a->parent?->parent_id === null ? 1 : 2);
            @endphp
            <tr @class(['bg-slate-50' => $a->is_header, 'opacity-60' => ! $a->is_active])>
                <x-ui.table.td><span class="font-mono text-sm text-slate-600">{{ $a->code }}</span></x-ui.table.td>
                <x-ui.table.td>
                    <span @class(['font-semibold text-slate-900' => $a->is_header, 'text-slate-800' => ! $a->is_header]) style="padding-left: {{ $level }}rem">{{ $a->name }}</span>
                    @if ($a->is_system)
                        <x-ui.badge color="info" class="ml-1">Sistem</x-ui.badge>
                    @endif
                    @unless ($a->is_active)
                        <x-ui.badge color="neutral" class="ml-1">Nonaktif</x-ui.badge>
                    @endunless
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$a->type->color()">{{ $a->type->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td muted>{{ $a->is_header ? 'Akun induk' : $a->normal_balance->label() }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $a->is_header ? '' : Format::rupiah(AccountsExport::balance($a)) }}</x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @if ($a->is_header)
                            @can('accounting.coa.create')
                                <x-ui.button variant="ghost" size="sm" icon="plus" :href="route('accounts.create', ['induk' => $a->id])" aria-label="Tambah akun di bawah {{ $a->name }}" />
                            @endcan
                        @else
                            @can('accounting.journals.view')
                                <x-ui.button variant="ghost" size="sm" icon="document-text" :href="route('journals.index', ['akun' => $a->id])" aria-label="Jurnal akun {{ $a->name }}" />
                            @endcan
                        @endif
                        @can('accounting.coa.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('accounts.edit', $a)" aria-label="Ubah {{ $a->name }}" />
                        @endcan
                        @if (! $a->is_system && auth()->user()->can('accounting.coa.delete'))
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $a->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('accounts.destroy', $a)) }}, nama: {{ \Illuminate\Support\Js::from($a->label()) }} })" />
                        @endif
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="6" icon="calculator" title="Belum ada akun yang cocok" description="Ubah filter, atau tambah akun baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>
