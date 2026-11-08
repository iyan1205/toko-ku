@php
    $tabs = array_filter([
        'saya' => $bisaMengajukan ? 'Pengajuan saya' : null,
        'persetujuan' => 'Perlu persetujuan saya',
        'semua' => $bisaLihatSemua ? 'Semua pengajuan' : null,
    ]);
    $tampilPegawai = $tab !== 'saya';
@endphp

<x-app-layout>
    <x-slot name="header">Cuti</x-slot>

    <div class="space-y-4">
        {{-- Sisa kuota tahun berjalan --}}
        @if ($bisaMengajukan && $kuota->isNotEmpty())
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($kuota as $k)
                    @php($sisa = max(0, $k['kuota'] - $k['terpakai']))
                    <div class="kartu p-4">
                        <p class="text-sm text-slate-500">{{ $k['nama'] }} {{ $tahunIni }}</p>
                        <p class="mt-1 text-2xl font-semibold text-slate-900">
                            {{ $sisa }} <span class="text-sm font-normal text-slate-500">dari {{ $k['kuota'] }} hari tersisa</span>
                        </p>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100" aria-hidden="true">
                            <div class="h-full rounded-full {{ $sisa === 0 ? 'bg-amber-500' : 'bg-slate-600' }}"
                                 style="width: {{ $k['kuota'] > 0 ? min(100, round($k['terpakai'] / $k['kuota'] * 100)) : 0 }}%"></div>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">Termasuk pengajuan yang masih menunggu.</p>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="kartu">
            {{-- Tab --}}
            <div class="flex flex-col gap-3 border-b border-slate-200 px-4 pt-3 sm:flex-row sm:items-end sm:justify-between">
                <nav class="-mb-px flex gap-4 overflow-x-auto" aria-label="Tab cuti">
                    @foreach ($tabs as $kunci => $label)
                        <a href="{{ route('leaves.index', ['tab' => $kunci]) }}"
                           @class([
                               'whitespace-nowrap border-b-2 px-1 pb-3 text-sm font-medium',
                               'border-slate-700 text-slate-900' => $tab === $kunci,
                               'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $kunci,
                           ])
                           @if ($tab === $kunci) aria-current="page" @endif>
                            {{ $label }}
                            @if ($kunci === 'persetujuan' && $jumlahPerluPersetujuan > 0)
                                <span class="ml-1 rounded-full bg-amber-500 px-2 py-0.5 text-xs font-semibold text-white">{{ $jumlahPerluPersetujuan }}</span>
                            @endif
                        </a>
                    @endforeach
                </nav>
                @if ($bisaMengajukan)
                    <a href="{{ route('leaves.create') }}" class="tombol-utama mb-3">Ajukan cuti</a>
                @endif
            </div>

            {{-- Filter --}}
            @if ($tab !== 'persetujuan')
                <form method="GET" action="{{ route('leaves.index') }}" class="grid gap-2 border-b border-slate-200 p-4 sm:grid-cols-2 lg:grid-cols-5">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    @if ($tab === 'semua')
                        <select name="pegawai" aria-label="Filter pegawai" class="isian">
                            <option value="">Semua pegawai</option>
                            @foreach ($pegawai as $p)
                                <option value="{{ $p->id }}" @selected((string) ($filter['pegawai'] ?? '') === (string) $p->id)>{{ $p->name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select name="jenis" aria-label="Filter jenis cuti" class="isian">
                        <option value="">Semua jenis</option>
                        @foreach ($jenisCuti as $j)
                            <option value="{{ $j->id }}" @selected((string) ($filter['jenis'] ?? '') === (string) $j->id)>{{ $j->nama }}</option>
                        @endforeach
                    </select>
                    <select name="status" aria-label="Filter status" class="isian">
                        <option value="">Semua status</option>
                        @foreach (\App\Enums\StatusCuti::cases() as $s)
                            <option value="{{ $s->value }}" @selected(($filter['status'] ?? '') === $s->value)>{{ $s->label() }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="tahun" value="{{ $filter['tahun'] ?? '' }}" min="2000" max="2100" placeholder="Tahun" aria-label="Filter tahun" class="isian">
                    <button type="submit" class="tombol-kedua">Terapkan filter</button>
                </form>
            @endif

            <div class="overflow-x-auto">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th>Nomor</th>
                            @if ($tampilPegawai)
                                <th>Pegawai</th>
                            @endif
                            <th>Jenis</th>
                            <th>Tanggal</th>
                            <th class="text-right">Hari</th>
                            <th>Status</th>
                            <th class="text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cuti as $c)
                            <tr>
                                <td class="whitespace-nowrap font-medium text-slate-900">{{ $c->nomor }}</td>
                                @if ($tampilPegawai)
                                    <td class="text-slate-900">{{ $c->user?->name }}</td>
                                @endif
                                <td>{{ $c->jenisCuti->nama }}</td>
                                <td class="whitespace-nowrap">{{ $c->rentangTanggal() }}</td>
                                <td class="text-right">{{ $c->jumlah_hari }}</td>
                                <td>
                                    @include('partials.badge-cuti', ['status' => $c->status])
                                    @if ($tahap = $c->tahapSekarang())
                                        <span class="mt-0.5 block text-xs text-slate-500">
                                            Tahap {{ $tahap->urutan }}/{{ $c->persetujuan->count() }}: {{ $tahap->label() }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    <a href="{{ route('leaves.show', $c) }}" class="tombol-tautan">{{ $tab === 'persetujuan' ? 'Putuskan' : 'Detail' }}</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $tampilPegawai ? 7 : 6 }}" class="px-4 py-10 text-center text-slate-500">
                                    {{ $tab === 'persetujuan' ? 'Tidak ada pengajuan yang menunggu keputusan Anda.' : 'Belum ada pengajuan cuti.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($cuti->hasPages())
                <div class="border-t border-slate-200 p-4">{{ $cuti->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
