{{-- Cost center / departemen: dimensi laporan di baris jurnal, buku besar, dan neraca saldo. --}}
<x-app-layout>
    <x-slot name="header">Cost Center</x-slot>
    <x-slot name="description">Bagian usaha untuk memilah pendapatan dan beban, misal Toko, Gudang, atau Penjualan Online. Dipilih per baris jurnal (opsional).</x-slot>
    <x-slot name="actions">
        @can('accounting.cost-centers.export')
            <x-ui.export-menu :href="route('cost-centers.export')" />
        @endcan
        @can('accounting.cost-centers.create')
            <x-ui.button :href="route('cost-centers.create')" icon="plus">Tambah cost center</x-ui.button>
        @endcan
    </x-slot>

    <x-ui.table :paginator="$costCenters">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari kode atau nama">
                <x-ui.select name="status" label="Status" hide-label :options="['aktif' => 'Aktif', 'nonaktif' => 'Nonaktif']" :selected="request('status')" placeholder="Aktif & nonaktif" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th sort="code">Kode</x-ui.table.th>
            <x-ui.table.th sort="name">Nama</x-ui.table.th>
            <x-ui.table.th>Departemen SDM</x-ui.table.th>
            <x-ui.table.th align="right">Baris jurnal</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @forelse ($costCenters as $c)
            <tr @class(['opacity-60' => ! $c->is_active])>
                <x-ui.table.td><span class="font-mono text-sm text-slate-600">{{ $c->code }}</span></x-ui.table.td>
                <x-ui.table.td>
                    <p class="font-medium text-slate-900">{{ $c->name }}
                        @unless ($c->is_active)
                            <x-ui.badge color="neutral" class="ml-1">Nonaktif</x-ui.badge>
                        @endunless
                    </p>
                    @if ($c->description)
                        <p class="text-xs text-slate-500">{{ $c->description }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $c->department?->name ?? '—' }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ number_format($c->lines_count, 0, ',', '.') }}</x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('accounting.trial-balance.view')
                            <x-ui.button variant="ghost" size="sm" icon="scale" :href="route('trial-balance.index', ['cost_center' => $c->id])" aria-label="Neraca saldo {{ $c->name }}" />
                        @endcan
                        @can('accounting.cost-centers.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('cost-centers.edit', $c)" aria-label="Ubah {{ $c->name }}" />
                        @endcan
                        @if ($c->lines_count === 0 && auth()->user()->can('accounting.cost-centers.delete'))
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $c->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('cost-centers.destroy', $c)) }}, nama: {{ \Illuminate\Support\Js::from($c->label()) }} })" />
                        @endif
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="building-office" title="Belum ada cost center"
                              description="Cost center opsional. Tambahkan bila ingin memilah laba rugi per bagian usaha." />
        @endforelse
    </x-ui.table>
</x-app-layout>
