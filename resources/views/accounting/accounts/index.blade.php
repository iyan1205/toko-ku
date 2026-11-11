{{--
    Bagan akun (COA) sebagai pohon: akun induk bisa diciutkan, saldo akun induk = jumlah akun di
    bawahnya. Filter menampilkan akun yang cocok beserta akun induknya. Ekspor = daftar datar.
--}}
@use('App\Support\Format')
@use('App\Enums\AccountType')
@use('Illuminate\Support\Js')
@php
    $indukIds = collect($rows)->filter(fn ($r) => $r['has_children'])->map(fn ($r) => $r['account']->id)->values();
@endphp

<x-app-layout>
    <x-slot name="header">Bagan Akun</x-slot>
    <x-slot name="description">Daftar akun untuk jurnal. Akun sistem dipakai jurnal otomatis (atur di Pengaturan → Akuntansi).</x-slot>
    <x-slot name="actions">
        @can('accounting.coa.import')
            <x-ui.button variant="secondary" icon="arrow-up-tray" :href="route('imports.accounts.create')">Impor</x-ui.button>
        @endcan
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
                              description="Pasang bagan akun standar toko retail Indonesia ({{ $standardCount }} akun), atau impor bagan akun sendiri dari Excel. Setelah itu jurnal otomatis dari penjualan dan pembelian mulai berjalan." />
            @can('accounting.coa.create')
                <form method="POST" action="{{ route('accounts.install') }}" class="-mt-6 flex justify-center pb-6">
                    @csrf
                    <x-ui.button type="submit" icon="sparkles">Pasang COA standar</x-ui.button>
                </form>
            @endcan
        </x-ui.card>
    @endunless

    @if ($missing->isNotEmpty())
        <x-ui.alert type="info" class="mb-6" title="Ada {{ $missing->count() }} akun standar baru">
            {{ $missing->join(', ') }}. Dipakai fitur baru (diskon penjualan, saldo awal, transfer antar cabang). Akun yang sudah ada tidak diubah.
            @can('accounting.coa.create')
                <form method="POST" action="{{ route('accounts.install') }}" class="mt-3">
                    @csrf
                    <x-ui.button type="submit" size="sm" icon="plus">Tambahkan akun standar</x-ui.button>
                </form>
            @endcan
        </x-ui.alert>
    @endif

    <x-ui.table x-data="{
            tutup: [],
            tertutup(ids) { return ids.some(id => this.tutup.includes(id)) },
            alih(id) { this.tutup = this.tutup.includes(id) ? this.tutup.filter(x => x !== id) : [...this.tutup, id] },
        }">
        <x-slot:toolbar>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <x-ui.filter-bar placeholder="Cari kode atau nama akun">
                    <x-ui.select name="tipe" label="Kelompok" hide-label :options="collect(AccountType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])" :selected="request('tipe')" placeholder="Semua kelompok" />
                    <x-ui.select name="status" label="Status" hide-label :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" :selected="request('status')" placeholder="Aktif & nonaktif" />
                </x-ui.filter-bar>
                @if ($indukIds->isNotEmpty())
                    <div class="flex gap-2">
                        <x-ui.button type="button" variant="ghost" size="sm" icon="chevron-double-up" x-on:click="tutup = {{ Js::from($indukIds) }}">Ciutkan semua</x-ui.button>
                        <x-ui.button type="button" variant="ghost" size="sm" icon="chevron-double-down" x-on:click="tutup = []">Buka semua</x-ui.button>
                    </div>
                @endif
            </div>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th>Akun</x-ui.table.th>
            <x-ui.table.th>Kelompok</x-ui.table.th>
            <x-ui.table.th>Saldo normal</x-ui.table.th>
            <x-ui.table.th align="right">Saldo</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($rows as $row)
            @php
                $a = $row['account'];
            @endphp
            <tr @class(['bg-slate-50' => $a->is_header, 'opacity-60' => ! $a->is_active]) x-show="! tertutup({{ Js::from($row['ancestors']) }})">
                <x-ui.table.td>
                    <div class="flex items-center gap-2" style="padding-left: {{ $row['depth'] * 1.25 }}rem">
                        @if ($row['has_children'])
                            <button type="button" class="rounded p-0.5 text-slate-500 hover:bg-slate-200 hover:text-slate-700" x-on:click="alih({{ $a->id }})"
                                    x-bind:aria-expanded="! tutup.includes({{ $a->id }})" aria-label="Ciutkan atau buka {{ $a->name }}">
                                <x-ui.icon name="chevron-down" variant="m" class="h-4 w-4 transition-transform" x-bind:class="tutup.includes({{ $a->id }}) && '-rotate-90'" />
                            </button>
                        @else
                            <span class="w-5"></span>
                        @endif
                        <span class="font-mono text-xs text-slate-500">{{ $a->code }}</span>
                        <span @class(['font-semibold text-slate-900' => $a->is_header, 'text-slate-800' => ! $a->is_header])>{{ $a->name }}</span>
                        @if ($a->is_system)
                            <x-ui.badge color="info">Sistem</x-ui.badge>
                        @endif
                        @if ($a->is_cash_bank)
                            <x-ui.badge color="success">Kas/bank</x-ui.badge>
                        @endif
                        @unless ($a->is_active)
                            <x-ui.badge color="neutral">Nonaktif</x-ui.badge>
                        @endunless
                    </div>
                </x-ui.table.td>
                <x-ui.table.td><x-ui.badge :color="$a->type->color()">{{ $a->type->label() }}</x-ui.badge></x-ui.table.td>
                <x-ui.table.td muted>{{ $a->is_header ? 'Akun induk' : $a->normal_balance->label() }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="$a->is_header ? 'font-semibold text-slate-900' : ''">{{ Format::rupiah($row['balance']) }}</x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @if ($a->is_header)
                            @can('accounting.coa.create')
                                <x-ui.button variant="ghost" size="sm" icon="plus" :href="route('accounts.create', ['induk' => $a->id])" aria-label="Tambah akun di bawah {{ $a->name }}" />
                            @endcan
                        @else
                            @can('accounting.ledger.view')
                                <x-ui.button variant="ghost" size="sm" icon="book-open" :href="route('ledger.index', ['akun' => $a->id])" aria-label="Buku besar {{ $a->name }}" />
                            @endcan
                        @endif
                        @can('accounting.coa.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('accounts.edit', $a)" aria-label="Ubah {{ $a->name }}" />
                        @endcan
                        @if (! $a->is_system && auth()->user()->can('accounting.coa.delete'))
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $a->name }}" class="text-danger-600 hover:text-danger-700"
                                x-on:click="$dispatch('konfirmasi-hapus', { action: {{ Js::from(route('accounts.destroy', $a)) }}, nama: {{ Js::from($a->label()) }} })" />
                        @endif
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="calculator" :title="$filtered ? 'Tidak ada akun yang cocok' : 'Belum ada akun'" description="Ubah filter, tambah akun baru, atau impor dari Excel." />
        @endforelse
    </x-ui.table>
</x-app-layout>
