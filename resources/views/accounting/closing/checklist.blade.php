{{-- Checklist tutup bulan: hal yang perlu beres sebelum periode dikunci. --}}
@use('App\Support\Format')
@php
    $belum = collect($items)->where('ok', false)->count();
@endphp

<x-app-layout>
    <x-slot name="header">Tutup bulan {{ Format::bulan($date) }}</x-slot>
    <x-slot name="description">{{ $belum === 0 ? 'Semua pemeriksaan beres.' : $belum.' hal perlu diperiksa sebelum periode dikunci.' }}</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="arrow-left" :href="route('closing.index', ['tahun' => $year])">Tutup Buku {{ $year }}</x-ui.button>
    </x-slot>

    <x-ui.card>
        <ul class="divide-y divide-slate-100">
            @foreach ($items as $item)
                <li class="flex items-start gap-3 py-3">
                    @if ($item['ok'])
                        <x-ui.icon name="check-circle" variant="s" class="mt-0.5 h-5 w-5 text-success-600" />
                    @else
                        <x-ui.icon name="exclamation-triangle" variant="s" class="mt-0.5 h-5 w-5 text-warning-500" />
                    @endif
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-slate-900">{{ $item['label'] }}</p>
                        <p class="text-sm text-slate-500">{{ $item['detail'] }}</p>
                    </div>
                    @if (! $item['ok'] && $item['url'])
                        <x-ui.button variant="ghost" size="sm" :href="$item['url']" icon-right="arrow-right">Buka</x-ui.button>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4">
            @if ($closed)
                <x-ui.badge color="success" dot>Periode sudah dikunci</x-ui.badge>
            @else
                <p class="text-sm text-slate-500">Setelah dikunci, transaksi bertanggal di bulan ini ditolak.{{ $belum > 0 ? ' Periode tetap bisa dikunci walau ada yang belum beres.' : '' }}</p>
                @can('accounting.periods.update')
                    <x-ui.button icon="lock-closed" x-data x-on:click="$dispatch('konfirmasi-hapus', {
                        action: {{ \Illuminate\Support\Js::from(route('fiscal-periods.close', [$year, $month])) }},
                        method: 'POST',
                        judul: {{ \Illuminate\Support\Js::from('Kunci periode '.Format::bulan($date).'?') }},
                        pesan: {{ \Illuminate\Support\Js::from($belum > 0 ? 'Masih ada '.$belum.' hal di checklist yang belum beres.' : 'Transaksi bertanggal di bulan ini akan ditolak sampai periode dibuka kembali.') }},
                        tombol: 'Ya, kunci',
                    })">Kunci periode</x-ui.button>
                @endcan
            @endif
        </div>
    </x-ui.card>
</x-app-layout>
