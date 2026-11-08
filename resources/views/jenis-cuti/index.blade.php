<x-app-layout>
    <x-slot name="header">Jenis Cuti</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="teks-bantu max-w-2xl">
            Atur kuota dan alur persetujuan tiap jenis cuti. Pengajuan diputuskan berurutan dari tahap 1.
            Jenis cuti tanpa tahap langsung disetujui.
        </p>
        <a href="{{ route('leave-types.create') }}" class="tombol-utama">Tambah jenis cuti</a>
    </div>

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
                            <span class="block text-xs font-normal text-slate-500">{{ $j->cutis_count }} pengajuan</span>
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
