{{-- Akuntansi → Tutup Buku: status bulan, checklist tutup bulan, dan tutup tahun (jurnal penutup ke Laba Ditahan). --}}
@use('App\Support\Format')
@use('App\Support\Uang')
@php
    $rp = fn (int $sen) => Format::rupiah(Uang::keDesimal($sen));
    $bolehTutup = auth()->user()->can('accounting.closing.approve');
    $totalLaba = array_sum($profits);
    $bulanTerbuka = $months->filter(fn ($m) => ! $m['closed'])->count();
@endphp

<x-app-layout>
    <x-slot name="header">Tutup Buku</x-slot>
    <x-slot name="description">Periksa checklist setiap akhir bulan, kunci periodenya, lalu tutup tahun setelah Desember selesai.</x-slot>
    <x-slot name="actions">
        <div class="flex items-center gap-1">
            <x-ui.button variant="secondary" size="sm" icon="chevron-left" :href="route('closing.index', ['tahun' => $year - 1])" aria-label="Tahun {{ $year - 1 }}" />
            <span class="min-w-[4rem] text-center text-sm font-semibold text-slate-900">{{ $year }}</span>
            <x-ui.button variant="secondary" size="sm" icon="chevron-right" :href="route('closing.index', ['tahun' => $year + 1])" aria-label="Tahun {{ $year + 1 }}" />
        </div>
    </x-slot>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Tutup bulan {{ $year }}">
            <ul class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($months as $m)
                    <li>
                        @if ($m['future'])
                            <div class="flex items-center justify-between rounded-lg border border-dashed border-slate-200 px-3 py-2 text-sm text-slate-400">
                                <span>{{ Format::bulan($m['date']) }}</span><span>Belum berjalan</span>
                            </div>
                        @else
                            <a href="{{ route('closing.checklist', [$year, $m['month']]) }}" class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm hover:border-primary-300 hover:bg-primary-50/40">
                                <span class="font-medium text-slate-800">{{ Format::bulan($m['date']) }}</span>
                                @if ($m['closed'])
                                    <x-ui.badge color="success" dot>Dikunci</x-ui.badge>
                                @else
                                    <x-ui.badge color="warning" dot>Terbuka</x-ui.badge>
                                @endif
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
            <p class="mt-4 text-xs text-slate-500">Klik bulan untuk melihat checklist dan menguncinya. Membuka kembali periode yang dikunci ada di
                <a href="{{ route('fiscal-periods.index', ['tahun' => $year]) }}" class="text-primary-700 hover:underline">Kunci Periode</a> dan hanya untuk pemegang izin tutup buku.</p>
        </x-ui.card>

        <x-ui.card title="Tutup tahun {{ $year }}">
            @if ($closingRecord)
                <x-ui.badge color="success" dot>Sudah ditutup</x-ui.badge>
                <p class="mt-3 text-sm text-slate-600">{{ $closingRecord->net_income >= 0 ? 'Laba' : 'Rugi' }} {{ Format::rupiah(abs((float) $closingRecord->net_income)) }} dipindah ke Laba Ditahan pada {{ Format::tanggal($closingRecord->created_at, true) }}.</p>
                <div class="mt-3"><x-ui.journal-links :document="$closingRecord" /></div>
                @if ($bolehTutup)
                    <x-ui.button variant="ghost" size="sm" icon="arrow-uturn-left" class="mt-4 text-danger-600" x-data x-on:click="$dispatch('open-modal', 'batal-tutup-{{ $closingRecord->id }}')">Batalkan tutup buku</x-ui.button>
                    <x-ui.void-dialog :name="'batal-tutup-'.$closingRecord->id" :action="route('closing.cancel-year', $closingRecord)"
                                      :title="'Batalkan tutup buku '.$year.'?'" confirm="Ya, batalkan">
                        Jurnal penutup dibalik dengan tanggal 31 Des {{ $year }}. Desember {{ $year }} harus dibuka dulu bila sudah dikunci.
                    </x-ui.void-dialog>
                @endif
            @else
                <dl class="grid gap-2 text-sm">
                    @forelse ($profits as $branchId => $laba)
                        <div class="flex justify-between gap-3"><dt class="text-slate-600">{{ $branchNames[$branchId] ?? 'Cabang #'.$branchId }}</dt><dd class="font-medium tabular-nums">{{ $rp($laba) }}</dd></div>
                    @empty
                        <p class="text-slate-500">Belum ada jurnal di tahun ini.</p>
                    @endforelse
                    @if (count($profits) > 1)
                        <div class="flex justify-between gap-3 border-t border-slate-200 pt-2 font-semibold"><dt>Jumlah</dt><dd class="tabular-nums">{{ $rp($totalLaba) }}</dd></div>
                    @endif
                </dl>
                @if (! $yearEnded)
                    <p class="mt-4 text-sm text-slate-500">Tahun {{ $year }} belum berakhir. Tutup tahun tersedia mulai 31 Des {{ $year }}.</p>
                @elseif ($bolehTutup)
                    <form method="POST" action="{{ route('closing.close-year', $year) }}" class="mt-4 grid gap-3" x-data
                          x-on:submit="if (! confirm({{ \Illuminate\Support\Js::from('Tutup buku '.$year.'? Pendapatan dan beban dinolkan ke Laba Ditahan per cabang.') }})) $event.preventDefault()">
                        @csrf
                        <x-ui.checkbox name="kunci" value="1" :checked="true" unchecked-value="0" label="Kunci semua bulan {{ $year }} ({{ $bulanTerbuka }} masih terbuka)" />
                        <x-ui.button type="submit" icon="lock-closed">Tutup tahun {{ $year }}</x-ui.button>
                    </form>
                @endif
            @endif
        </x-ui.card>
    </div>

    @if ($history->isNotEmpty())
        <x-ui.table :sticky="false" class="mt-6">
            <x-slot:toolbar><p class="text-sm font-medium text-slate-700">Riwayat tutup buku</p></x-slot:toolbar>
            <x-slot:head>
                <x-ui.table.th>Tahun</x-ui.table.th>
                <x-ui.table.th align="right">Laba (rugi)</x-ui.table.th>
                <x-ui.table.th>Status</x-ui.table.th>
                <x-ui.table.th>Catatan</x-ui.table.th>
            </x-slot:head>
            @foreach ($history as $h)
                <tr>
                    <x-ui.table.td><a href="{{ route('closing.index', ['tahun' => $h->fiscal_year]) }}" class="font-medium text-primary-700 hover:underline">{{ $h->fiscal_year }}</a></x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ Format::rupiah($h->net_income) }}</x-ui.table.td>
                    <x-ui.table.td>
                        @if ($h->isCancelled())
                            <x-ui.badge color="neutral">Dibatalkan</x-ui.badge>
                        @else
                            <x-ui.badge color="success">Ditutup</x-ui.badge>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td muted>
                        {{ Format::tanggal($h->created_at, true) }}
                        @if ($h->isCancelled())
                            <span class="block text-xs">Dibatalkan {{ Format::tanggal($h->cancelled_at, true) }} oleh {{ $h->cancelledBy?->name ?? '-' }}: {{ $h->cancel_reason }}</span>
                        @endif
                    </x-ui.table.td>
                </tr>
            @endforeach
        </x-ui.table>
    @endif
</x-app-layout>
