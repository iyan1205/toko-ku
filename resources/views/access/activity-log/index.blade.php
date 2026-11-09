{{-- Log aktivitas: siapa melakukan apa dan kapan. Rincian perubahan kolom ditampilkan per baris. --}}
@php
    $tampil = fn ($nilai) => match (true) {
        $nilai === null, $nilai === '' => '(kosong)',
        is_bool($nilai) => $nilai ? 'Ya' : 'Tidak',
        is_array($nilai) => implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $nilai)),
        default => (string) $nilai,
    };
    $warnaKelompok = ['auth' => 'info', 'pengguna' => 'primary', 'hak-akses' => 'warning', 'penjualan' => 'success', 'pembelian' => 'success'];
@endphp

<x-app-layout>
    <x-slot name="header">Log Aktivitas</x-slot>

    <x-ui.table :paginator="$activities">
        <x-slot:toolbar>
            <x-ui.filter-bar placeholder="Cari aktivitas">
                <x-ui.select name="kelompok" label="Kelompok" hide-label :options="$logNames" :selected="request('kelompok')" placeholder="Semua kelompok" />
                <x-ui.select name="pengguna" label="Pengguna" hide-label :options="$users" :selected="request('pengguna')" placeholder="Semua pengguna" />
                <x-ui.input name="dari" label="Dari tanggal" hide-label type="date" :value="request('dari')" />
                <x-ui.input name="sampai" label="Sampai tanggal" hide-label type="date" :value="request('sampai')" />
            </x-ui.filter-bar>
        </x-slot:toolbar>

        <x-slot:head>
            <x-ui.table.th>Waktu</x-ui.table.th>
            <x-ui.table.th>Pengguna</x-ui.table.th>
            <x-ui.table.th>Aktivitas</x-ui.table.th>
            <x-ui.table.th>Kelompok</x-ui.table.th>
        </x-slot:head>

        @forelse ($activities as $a)
            @php
                $baru = $a->properties['attributes'] ?? [];
                $lama = $a->properties['old'] ?? [];
                $tambahan = collect($a->properties)->except(['attributes', 'old'])->all();
            @endphp
            <tr class="align-top">
                <x-ui.table.td muted class="whitespace-nowrap">{{ \App\Support\Format::tanggal($a->created_at, true) }}</x-ui.table.td>
                <x-ui.table.td>
                    {{ $a->causer?->name ?? 'Sistem / tamu' }}
                </x-ui.table.td>
                <x-ui.table.td>
                    <p class="text-slate-900">{{ $a->description }}</p>

                    @if ($baru !== [] || $tambahan !== [])
                        <details class="mt-1 text-xs text-slate-500">
                            <summary class="cursor-pointer select-none text-primary-600 hover:underline">Rincian</summary>
                            <dl class="mt-2 grid gap-1">
                                @foreach ($baru as $kolom => $nilai)
                                    <div class="flex flex-wrap gap-x-2">
                                        <dt class="font-medium text-slate-600">{{ str_replace('_', ' ', $kolom) }}:</dt>
                                        <dd>
                                            @if (array_key_exists($kolom, $lama))
                                                <span class="text-slate-400 line-through">{{ $tampil($lama[$kolom]) }}</span>
                                                <span aria-hidden="true">→</span>
                                            @endif
                                            <span class="text-slate-800">{{ $tampil($nilai) }}</span>
                                        </dd>
                                    </div>
                                @endforeach
                                @foreach ($tambahan as $kolom => $nilai)
                                    <div class="flex flex-wrap gap-x-2">
                                        <dt class="font-medium text-slate-600">{{ str_replace('_', ' ', $kolom) }}:</dt>
                                        <dd class="text-slate-800">{{ $tampil($nilai) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td>
                    <x-ui.badge :color="$warnaKelompok[$a->log_name] ?? 'neutral'">{{ $logNames[$a->log_name] ?? $a->log_name }}</x-ui.badge>
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="4" icon="clipboard-document-list" title="Belum ada aktivitas yang cocok" description="Ubah filter atau rentang tanggal." />
        @endforelse
    </x-ui.table>
</x-app-layout>
