<x-app-layout>
    <x-slot name="header">Jenis Cuti</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="teks-bantu max-w-2xl">
            Atur kuota dan alur persetujuan tiap jenis cuti. Pengajuan diputuskan berurutan dari tahap 1.
            Jenis cuti tanpa tahap langsung disetujui.
        </p>
        <div class="flex flex-wrap gap-2">
            @can('sdm.leave-types.create')
                <form method="POST" action="{{ route('leave-types.standard') }}">
                    @csrf
                    <button type="submit" class="tombol-kedua" title="Tahunan, sakit, melahirkan, menikah, duka, tanpa upah, dst.">Lengkapi jenis cuti standar</button>
                </form>
            @endcan
            <a href="{{ route('leave-types.create') }}" class="tombol-utama">Tambah jenis cuti</a>
        </div>
    </div>

    @if ($alurCuti->isNotEmpty())
        <div class="mt-4 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-900">
            Alur di Pengaturan → Alur Persetujuan berlaku lebih dulu: {{ $alurCuti->map(fn ($f) => $f->name.' ('.$f->conditionSummary().')')->join('; ') }}.
            Tahap di jenis cuti hanya dipakai bila tidak ada alur yang cocok.
        </div>
    @endif

    <div class="kartu mt-4 overflow-x-auto">
        <table class="tabel">
            <thead>
                <tr>
                    <th>Nama</th>
                    <th class="text-right">Kuota / tahun</th>
                    <th>Di absensi</th>
                    <th>Alur persetujuan</th>
                    <th>Status</th>
                    <th class="text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($jenisCuti as $j)
                    <tr>
                        <td class="font-medium text-slate-900">
                            {{ $j->nama }}
                            <span class="block text-xs font-normal text-slate-500">
                                {{ \App\Models\JenisCuti::CATEGORIES[$j->category] ?? '' }} · {{ $j->cutis_count }} pengajuan
                                @unless ($j->is_paid) · tanpa upah @endunless
                                @if ($j->gender) · khusus {{ mb_strtolower($j->gender->label()) }} @endif
                                @if ($j->max_days_per_request) · maks {{ $j->max_days_per_request }} hari @endif
                                @if ($j->requires_document) · wajib surat @endif
                            </span>
                            @if ($j->quota_tiers)
                                <span class="block text-xs font-normal text-slate-500">{{ $j->quotaTiersLabel() }}</span>
                            @endif
                        </td>
                        <td class="text-right">{{ $j->punyaKuota() ? $j->kuota_tahunan . ' hari' : 'Tanpa kuota' }}</td>
                        <td>@include('partials.badge-absensi', ['status' => $j->status_absensi])</td>
                        <td>
                            @if ($j->tahap->isEmpty())
                                <span class="text-slate-500">Langsung disetujui</span>
                            @else
                                <ol class="flex flex-wrap items-center gap-1.5 text-sm">
                                    @foreach ($j->tahap as $t)
                                        <li class="flex items-center gap-1.5">
                                            @unless ($loop->first)<span class="text-slate-400" aria-hidden="true">→</span>@endunless
                                            <span class="badge-utilitas">{{ $t->urutan }}. {{ $t->label() }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            @endif
                        </td>
                        <td>
                            @if ($j->is_active)
                                <span class="badge-berhasil">Aktif</span>
                            @else
                                <span class="badge-utilitas">Nonaktif</span>
                            @endif
                        </td>
                        <td>
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('leave-types.edit', $j) }}" class="tombol-tautan">Ubah</a>
                                @if ($j->tahap->isNotEmpty())
                                    @can('settings.approvals.create')
                                        <form method="POST" action="{{ route('leave-types.to-flow', $j) }}"
                                              @submit="if (! confirm('Pindahkan tahap {{ $j->nama }} ke Pengaturan → Alur Persetujuan? Pengajuan baru akan memakai alur itu.')) $event.preventDefault()">
                                            @csrf
                                            <button type="submit" class="tombol-tautan">Ke alur persetujuan</button>
                                        </form>
                                    @endcan
                                @endif
                                @if ($j->cutis_count === 0)
                                    <button type="button" class="text-sm font-medium text-amber-700 hover:underline"
                                            @click="$dispatch('konfirmasi-hapus', { action: @js(route('leave-types.destroy', $j)), nama: @js('Jenis cuti ' . $j->nama) })">
                                        Hapus
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            Belum ada jenis cuti. Klik "Tambah jenis cuti" untuk membuat yang pertama, mis. Cuti Tahunan 12 hari.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-app-layout>
