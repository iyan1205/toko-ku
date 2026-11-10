{{-- Daftar pelanggan: cari, filter yang masih punya piutang, urutkan, ekspor. --}}
<x-app-layout>
    <x-slot name="header">Pelanggan</x-slot>
    <x-slot name="description">Pelanggan terdaftar beserta total belanja dan saldo piutangnya.</x-slot>
    <x-slot name="actions">
        @can('pos.customers.import')
            <x-ui.button variant="secondary" icon="arrow-up-tray" :href="route('imports.customers.create')">Impor</x-ui.button>
        @endcan
        @can('pos.customers.export')
            <x-ui.export-menu :href="route('customers.export')" />
        @endcan
        @can('pos.customers.create')
            <x-ui.button :href="route('customers.create')" icon="plus">Tambah pelanggan</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$customers">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari kode, nama, atau nomor HP">
                <x-ui.select name="piutang" label="Piutang" hide-label :options="['ada' => 'Masih punya piutang']" :selected="request('piutang')" placeholder="Semua pelanggan" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="name">Pelanggan</x-ui.table.th>
            <x-ui.table.th>Kontak</x-ui.table.th>
            <x-ui.table.th align="right" sort="sales">Total belanja</x-ui.table.th>
            <x-ui.table.th align="right" sort="receivable">Saldo piutang</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($customers as $c)
            <tr>
                <x-ui.table.td>
                    <a href="{{ route('customers.show', $c) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $c->name }}</a>
                    <p class="font-mono text-xs text-slate-500">
                        {{ $c->code }}
                        @if ($c->is_default)
                            <x-ui.badge color="neutral" class="ml-1 font-sans">Bawaan sistem</x-ui.badge>
                        @endif
                    </p>
                </x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-700">{{ $c->phone ?: '—' }}</p>
                    @if ($c->email)
                        <p class="text-xs text-slate-500">{{ $c->email }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric muted>
                    @rupiah($c->sales_total ?? 0)
                    <p class="text-xs text-slate-400">{{ \App\Support\Format::jumlah($c->sales_count ?? 0) }} transaksi</p>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric :class="(float) $c->receivable_balance > 0 ? 'font-medium text-warning-700' : 'text-slate-400'">@rupiah($c->receivable_balance)</x-ui.table.td>
                <x-ui.table.td align="right">
                    @unless ($c->is_default)
                        <div class="flex items-center justify-end gap-1">
                            @can('pos.customers.update')
                                <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('customers.edit', $c)" aria-label="Ubah {{ $c->name }}" />
                            @endcan
                            @can('pos.customers.delete')
                                <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $c->name }}" class="text-danger-600 hover:text-danger-700"
                                    x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('customers.destroy', $c)) }}, nama: {{ \Illuminate\Support\Js::from($c->name) }} })" />
                            @endcan
                        </div>
                    @endunless
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="users" title="Tidak ada pelanggan yang cocok" description="Ubah kata kunci, atau tambah pelanggan baru." />
        @endforelse
    </x-ui.table>
</x-app-layout>
