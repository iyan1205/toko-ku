{{-- Daftar aset tetap dengan nilai buku. --}}
@use('App\Support\Format')
@use('App\Models\FixedAsset')

<x-app-layout>
    <x-slot name="header">Aset Tetap</x-slot>
    <x-slot name="description">Peralatan, kendaraan, dan bangunan usaha beserta penyusutannya. Penyusutan dijurnal otomatis setiap tanggal 1 untuk bulan sebelumnya.</x-slot>
    <x-slot name="actions">
        @can('accounting.assets.export')
            <x-ui.export-menu :href="route('fixed-assets.export')" />
        @endcan
        @can('accounting.assets.create')
            <x-ui.button icon="plus" :href="route('fixed-assets.create')">Catat aset</x-ui.button>
        @endcan
    </x-slot>

    @include('accounting.assets.nav')

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Aset aktif" :value="Format::jumlah($summary->jumlah)" icon="cube" color="neutral" />
        <x-ui.stat-card label="Harga perolehan" :value="Format::rupiah($summary->harga)" icon="banknotes" color="info" />
        <x-ui.stat-card label="Nilai buku" :value="Format::rupiah((float) $summary->harga - (float) $summary->akumulasi)" icon="scale" :hint="'Akumulasi '.Format::rupiah($summary->akumulasi)" />
    </div>

    <x-ui.table :paginator="$assets">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari kode, nama, nomor seri, atau lokasi">
                <x-ui.select name="kategori" label="Kategori" hide-label :options="$categories" :selected="request('kategori')" placeholder="Semua kategori" />
                <x-ui.select name="status" label="Status" hide-label :options="[FixedAsset::ACTIVE => 'Aktif', FixedAsset::DISPOSED => 'Dilepas']" :selected="request('status')" placeholder="Semua status" />
            </x-ui.filter-bar>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th sort="code">Aset</x-ui.table.th>
            <x-ui.table.th>Lokasi / penanggung jawab</x-ui.table.th>
            <x-ui.table.th sort="date">Perolehan</x-ui.table.th>
            <x-ui.table.th align="right" sort="cost">Harga</x-ui.table.th>
            <x-ui.table.th align="right">Nilai buku</x-ui.table.th>
        </x-slot:head>
        @forelse ($assets as $a)
            <tr @class(['opacity-60' => $a->isDisposed()])>
                <x-ui.table.td>
                    <a href="{{ route('fixed-assets.show', $a) }}" class="font-medium text-slate-900 hover:text-primary-700 hover:underline">{{ $a->name }}</a>
                    <p class="text-xs text-slate-500"><span class="font-mono">{{ $a->code }}</span> · {{ $a->category?->name }}
                        @if ($a->isDisposed())
                            · <x-ui.badge color="neutral">Dilepas</x-ui.badge>
                        @endif
                    </p>
                </x-ui.table.td>
                <x-ui.table.td muted>{{ $a->holderLabel() ?? '—' }}</x-ui.table.td>
                <x-ui.table.td numeric muted>{{ Format::tanggal($a->acquisition_date) }}</x-ui.table.td>
                <x-ui.table.td align="right" numeric>@rupiah($a->acquisition_cost)</x-ui.table.td>
                <x-ui.table.td align="right" numeric class="font-medium">{{ $a->isDisposed() ? '—' : Format::rupiah($a->bookValue()) }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="5" icon="cube" title="Belum ada aset tetap" description="Catat aset yang dibeli, atau aset lama yang sudah dimiliki sebelum memakai aplikasi (dengan akumulasi penyusutannya)." />
        @endforelse
    </x-ui.table>
</x-app-layout>
