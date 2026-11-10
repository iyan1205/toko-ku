{{-- Pratinjau / hasil impor: ringkasan, baris dengan kesalahannya, proses, dan laporan kesalahan. --}}
@use('App\Support\Format')
@php
    $kolom = $definition->columns();
@endphp

<x-app-layout>
    <x-slot name="header">Impor {{ $definition->title() }}: {{ $batch->file_name }}</x-slot>
    <x-slot name="description">Diunggah {{ Format::tanggal($batch->created_at, true) }} · {{ $batch->statusLabel() }}</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" :href="route('imports.'.$type.'.create')">{{ $batch->isPreviewed() ? 'Unggah ulang' : 'Impor lagi' }}</x-ui.button>
        @if ($batch->invalid_rows > 0)
            <x-ui.button variant="secondary" icon="arrow-down-tray" :href="route('imports.'.$type.'.errors', $batch)">Unduh baris salah</x-ui.button>
        @endif
        @if ($batch->isPreviewed() && $batch->valid_rows > 0)
            <form method="POST" action="{{ route('imports.'.$type.'.commit', $batch) }}" x-data="{ kirim: false }" x-on:submit="kirim = true">
                @csrf
                <x-ui.button type="submit" icon="check" x-bind:disabled="kirim">
                    Proses {{ $batch->valid_rows }} baris{{ $batch->invalid_rows > 0 ? ', lewati '.$batch->invalid_rows.' yang salah' : '' }}
                </x-ui.button>
            </form>
        @elseif (! $batch->isPreviewed())
            <x-ui.button :href="route($definition->indexRoute())">Lihat {{ mb_strtolower($definition->title()) }}</x-ui.button>
        @endif
    </x-slot>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <x-ui.stat-card label="Baris data" :value="Format::jumlah($batch->total_rows)" icon="table-cells" />
        <x-ui.stat-card label="Benar" :value="Format::jumlah($batch->valid_rows)" icon="check-circle" color="success" />
        <x-ui.stat-card label="Salah" :value="Format::jumlah($batch->invalid_rows)" icon="exclamation-triangle" :color="$batch->invalid_rows > 0 ? 'danger' : 'primary'" />
    </div>

    @if ($batch->isPreviewed())
        <x-ui.alert :type="$batch->invalid_rows > 0 ? 'warning' : 'info'" class="mb-6">
            Belum ada data yang berubah. {{ $definition->matchingRule() }}
            @if ($batch->valid_rows === 0)
                Semua baris salah: perbaiki berkasnya (unduh baris salah) lalu unggah ulang.
            @endif
        </x-ui.alert>
    @else
        <x-ui.alert type="success" class="mb-6" title="Impor selesai {{ Format::tanggal($batch->completed_at, true) }}">
            {{ $batch->created_count }} {{ mb_strtolower($definition->title()) }} ditambahkan, {{ $batch->updated_count }} diperbarui{{ $batch->invalid_rows > 0 ? ', '.$batch->invalid_rows.' baris salah dilewati' : '' }}.
        </x-ui.alert>
    @endif

    <nav class="mb-4 flex gap-1 border-b border-slate-200" aria-label="Saring baris">
        @foreach ([null => 'Semua', 'salah' => 'Salah', 'valid' => 'Benar'] as $kunci => $label)
            <a href="{{ route('imports.'.$type.'.show', array_filter(['batch' => $batch->id, 'tampil' => $kunci])) }}" @class([
                '-mb-px border-b-2 px-4 py-2 text-sm font-medium',
                'border-primary-600 text-primary-700' => $filter === ($kunci ?: null),
                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => $filter !== ($kunci ?: null),
            ])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Baris</x-ui.table.th>
            @foreach ($kolom as $k)
                <x-ui.table.th>{{ $k['label'] }}</x-ui.table.th>
            @endforeach
            <x-ui.table.th>Hasil pemeriksaan</x-ui.table.th>
        </x-slot:head>
        @forelse ($rows as $row)
            <tr @class(['bg-danger-50/40' => $row['errors'] !== []])>
                <x-ui.table.td numeric muted>{{ $row['line'] }}</x-ui.table.td>
                @foreach (array_keys($kolom) as $key)
                    <x-ui.table.td><span class="block max-w-[14rem] truncate">{{ $row['raw'][$key] ?? '' }}</span></x-ui.table.td>
                @endforeach
                <x-ui.table.td>
                    @if ($row['errors'] === [])
                        <x-ui.badge color="success">Benar</x-ui.badge>
                    @else
                        <ul class="grid gap-0.5 text-xs text-danger-700">
                            @foreach ($row['errors'] as $pesan)
                                <li>{{ $pesan }}</li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty :colspan="count($kolom) + 2" title="Tidak ada baris" />
        @endforelse
        @if ($hidden > 0)
            <x-slot:footer>
                <p class="text-sm text-slate-500">{{ $hidden }} baris lain tidak ditampilkan. Unduh baris salah untuk melihat semua kesalahan.</p>
            </x-slot:footer>
        @endif
    </x-ui.table>
</x-app-layout>
