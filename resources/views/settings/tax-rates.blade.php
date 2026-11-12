{{-- Pengaturan → Tarif Pajak: tarif PPh potong dengan tanggal berlaku (riwayat per kode). --}}
@use('App\Support\Format')
@php
    $bolehUbah = auth()->user()->can('settings.taxes.update');
@endphp

<x-app-layout>
    <x-slot name="header">Tarif Pajak</x-slot>
    <x-slot name="description">Tarif PPh 23 dan PPh 4(2) yang dipotong di tagihan pembelian. Tarif yang dipakai adalah yang berlaku pada tanggal tagihan. Tarif PPN kasir diatur di Pengaturan → Pajak.</x-slot>

    <x-ui.alert type="warning" class="mb-6" title="Periksa tarif terbaru">Nilai awal hanya titik mulai. Cocokkan dengan peraturan pajak terbaru atau konsultan pajak Anda. Bila tarif berubah, tambahkan baris baru dengan tanggal berlakunya; jangan ubah baris lama.</x-ui.alert>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="grid gap-6 lg:col-span-2">
            @forelse ($groups as $code => $rates)
                <x-ui.table :sticky="false">
                    <x-slot:toolbar>
                        <div>
                            <h2 class="text-sm font-semibold text-slate-900">{{ $rates->first()->name }} <span class="font-mono text-xs text-slate-500">{{ $code }}</span></h2>
                            <p class="text-sm text-slate-500">{{ $rates->first()->kindLabel() }}{{ $rates->first()->description ? ' · '.$rates->first()->description : '' }}</p>
                        </div>
                    </x-slot:toolbar>
                    <x-slot:head>
                        <x-ui.table.th>Berlaku mulai</x-ui.table.th>
                        <x-ui.table.th align="right">Tarif</x-ui.table.th>
                        <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                    </x-slot:head>
                    @foreach ($rates as $r)
                        <tr>
                            <x-ui.table.td>{{ Format::tanggal($r->effective_from) }} @if ($loop->first)<x-ui.badge color="success" class="ml-1">Terbaru</x-ui.badge>@endif</x-ui.table.td>
                            <x-ui.table.td align="right" numeric>{{ (float) $r->rate }}%</x-ui.table.td>
                            <x-ui.table.td align="right">
                                @if ($bolehUbah)
                                    <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus tarif {{ $r->name }} {{ Format::tanggal($r->effective_from) }}" x-data
                                        x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('settings.tax-rates.destroy', $r)) }}, nama: {{ \Illuminate\Support\Js::from($r->name.' mulai '.Format::tanggal($r->effective_from)) }} })" />
                                @endif
                            </x-ui.table.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @empty
                <x-ui.card><x-ui.empty-state icon="receipt-percent" title="Belum ada tarif PPh" /></x-ui.card>
            @endforelse
        </div>

        @if ($bolehUbah)
            <form method="POST" action="{{ route('settings.tax-rates.store') }}">
                @csrf
                <x-ui.form-section title="Tarif baru" description="Untuk mengubah tarif yang ada, pakai kode yang sama dengan tanggal berlaku baru.">
                    <x-ui.select name="kind" label="Jenis" required :options="$kinds" />
                    <x-ui.input name="code" label="Kode" required maxlength="30" placeholder="Misal PPH23-JASA" list="kode-tarif" />
                    <datalist id="kode-tarif">
                        @foreach ($groups->keys() as $code)
                            <option value="{{ $code }}">
                        @endforeach
                    </datalist>
                    <x-ui.input name="name" label="Nama" required maxlength="100" />
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-ui.input name="rate" label="Tarif (%)" type="number" min="0" max="100" step="0.01" required />
                        <x-ui.input name="effective_from" label="Berlaku mulai" type="date" required :value="now()->format('Y-m-d')" />
                    </div>
                    <x-ui.input name="description" label="Keterangan" maxlength="255" placeholder="Dasar hukum atau catatan" />
                    <x-slot:footer><x-ui.button type="submit">Simpan tarif</x-ui.button></x-slot:footer>
                </x-ui.form-section>
            </form>
        @endif
    </div>
</x-app-layout>
