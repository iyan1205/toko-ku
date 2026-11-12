{{-- Kategori aset: umur ekonomis, metode penyusutan, dan akun. --}}
<x-app-layout>
    <x-slot name="header">Kategori Aset</x-slot>
    <x-slot name="description">Aturan penyusutan dan akun untuk setiap jenis aset. Disalin ke aset saat dicatat, jadi mengubah kategori tidak mengubah aset lama.</x-slot>
    <x-slot name="actions">
        @can('accounting.assets.create')
            <form method="POST" action="{{ route('asset-categories.install') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="sparkles">Pasang kategori standar</x-ui.button>
            </form>
            <x-ui.button icon="plus" :href="route('asset-categories.create')">Tambah kategori</x-ui.button>
        @endcan
    </x-slot>

    @include('accounting.assets.nav')

    <x-ui.alert type="info" class="mb-6">Kategori standar mengikuti kelompok harta berwujud untuk pajak (kelompok 1: 4 tahun, kelompok 2: 8 tahun, bangunan permanen: 20 tahun). Cocokkan umur dan tarifnya dengan konsultan pajak Anda.</x-ui.alert>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Kategori</x-ui.table.th>
            <x-ui.table.th>Aturan</x-ui.table.th>
            <x-ui.table.th>Akun</x-ui.table.th>
            <x-ui.table.th align="right">Aset</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>
        @forelse ($categories as $c)
            <tr @class(['opacity-60' => ! $c->is_active])>
                <x-ui.table.td>
                    <p class="font-medium text-slate-900"><span class="font-mono text-xs text-slate-500">{{ $c->code }}</span> {{ $c->name }}</p>
                    @unless ($c->is_active)
                        <x-ui.badge color="neutral">Nonaktif</x-ui.badge>
                    @endunless
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $c->ruleLabel() }}</x-ui.table.td>
                <x-ui.table.td class="text-xs text-slate-500">
                    <p>Aset: {{ $c->assetAccount?->label() }}</p>
                    <p>Akumulasi: {{ $c->accumulatedAccount?->label() }}</p>
                    <p>Beban: {{ $c->expenseAccount?->label() }}</p>
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>{{ $c->assets_count }}</x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex justify-end gap-1">
                        @can('accounting.assets.update')
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('asset-categories.edit', $c)" aria-label="Ubah {{ $c->name }}" />
                        @endcan
                        @if ($c->assets_count === 0 && auth()->user()->can('accounting.assets.delete'))
                            <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus {{ $c->name }}" x-data
                                x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('asset-categories.destroy', $c)) }}, nama: {{ \Illuminate\Support\Js::from($c->name) }} })" />
                        @endif
                    </div>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="cube" title="Belum ada kategori" description="Klik Pasang kategori standar, atau tambah kategori sendiri." />
        @endforelse
    </x-ui.table>
</x-app-layout>
