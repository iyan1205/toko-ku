@php
    $tahapSekarang = $cuti->tahapSekarang();
    $jumlahTahap = $cuti->persetujuan->count();
@endphp

<x-app-layout>
    <x-slot name="header">Pengajuan cuti {{ $cuti->nomor }}</x-slot>

    <div class="grid max-w-5xl gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            {{-- Ringkasan --}}
            <div class="kartu p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-lg font-semibold text-slate-900">{{ $cuti->user?->name }}</p>
                        <p class="teks-bantu">Diajukan @tanggal($cuti->created_at, true)</p>
                    </div>
                    @include('partials.badge-cuti', ['status' => $cuti->status])
                </div>

                <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-slate-500">Jenis cuti</dt>
                        <dd class="mt-0.5 font-medium text-slate-900">{{ $cuti->jenisCuti->nama }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Tanggal</dt>
                        <dd class="mt-0.5 font-medium text-slate-900">{{ $cuti->rentangTanggal() }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Lama</dt>
                        <dd class="mt-0.5 font-medium text-slate-900">{{ $cuti->jumlah_hari }} hari</dd>
                    </div>
                    <div class="sm:col-span-3">
                        <dt class="text-slate-500">Alasan</dt>
                        <dd class="mt-0.5 whitespace-pre-line text-slate-900">{{ $cuti->alasan }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Keputusan approver --}}
            @if ($bisaMemutuskan)
                <form method="POST" action="{{ route('leaves.approve', $cuti) }}" x-data="{ mengirim: false }" @submit="mengirim = true"
                      class="kartu space-y-4 border-amber-300 p-6">
                    @csrf
                    <div>
                        <h2 class="judul-bagian">Keputusan Anda · Tahap {{ $tahapSekarang->urutan }} dari {{ $jumlahTahap }}</h2>
                        @unless ($tahapSekarang->ditujukanKe(auth()->user()))
                            <p class="mt-1 text-sm text-amber-700">Tahap ini untuk {{ $tahapSekarang->label() }}. Anda memutuskan sebagai Super Admin.</p>
                        @endunless
                    </div>

                    <div>
                        <label for="catatan" class="label-isian">Catatan <span class="font-normal text-slate-400">(wajib bila menolak)</span></label>
                        <textarea id="catatan" name="catatan" rows="2" maxlength="255"
                                  @class(['isian mt-1', 'isian-salah' => $errors->has('catatan')])>{{ old('catatan') }}</textarea>
                        @error('catatan') <p class="mt-1 text-sm text-amber-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="submit" formaction="{{ route('leaves.reject', $cuti) }}" class="tombol-bahaya" :disabled="mengirim">Tolak</button>
                        <button type="submit" class="tombol-utama" :disabled="mengirim">
                            {{ $tahapSekarang->urutan === $jumlahTahap ? 'Setujui (tahap terakhir)' : 'Setujui & teruskan' }}
                        </button>
                    </div>
                </form>
            @endif

            {{-- Catatan absensi setelah disetujui --}}
            @if ($cuti->status === \App\Enums\StatusCuti::Disetujui)
                <div class="kartu">
                    <div class="kartu-kepala">
                        <h2 class="kartu-judul">Tercatat di absensi</h2>
                    </div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @forelse ($absensi as $a)
                            <li class="flex items-center justify-between gap-3 px-5 py-2.5">
                                <span>{{ $a->tanggal->locale('id')->translatedFormat('l, d-m-Y') }}</span>
                                <span class="flex items-center gap-2">
                                    @if ($a->clock_in)
                                        <span class="text-xs text-slate-500">masuk {{ $a->clock_in->format('H:i') }}</span>
                                    @endif
                                    @include('partials.badge-absensi', ['status' => $a->status])
                                </span>
                            </li>
                        @empty
                            <li class="px-5 py-4 text-slate-500">Belum ada catatan absensi pada tanggal cuti ini.</li>
                        @endforelse
                    </ul>
                </div>
            @endif
        </div>

        {{-- Alur persetujuan --}}
        <div class="space-y-4">
            <div class="kartu p-5">
                <h2 class="judul-bagian">Alur persetujuan</h2>

                <ol class="mt-4 space-y-4">
                    <li class="flex gap-3">
                        <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-slate-200 text-xs font-semibold text-slate-700" aria-hidden="true">✓</span>
                        <div class="text-sm">
                            <p class="font-medium text-slate-900">Diajukan</p>
                            <p class="text-slate-500">{{ $cuti->user?->name }} · @tanggal($cuti->created_at, true)</p>
                        </div>
                    </li>

                    @forelse ($cuti->persetujuan as $p)
                        @php
                            $aktif = $tahapSekarang?->is($p);
                            $tidakDiperlukan = $p->status === \App\Enums\StatusPersetujuan::Menunggu && ! $aktif;
                            $lingkaran = match (true) {
                                $p->status === \App\Enums\StatusPersetujuan::Disetujui => 'bg-emerald-600 text-white',
                                $p->status === \App\Enums\StatusPersetujuan::Ditolak => 'bg-rose-600 text-white',
                                $aktif => 'bg-amber-500 text-white',
                                default => 'bg-slate-100 text-slate-400',
                            };
                        @endphp
                        <li class="flex gap-3">
                            <span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full text-xs font-semibold {{ $lingkaran }}">{{ $p->urutan }}</span>
                            <div class="min-w-0 text-sm">
                                <p @class(['font-medium', 'text-slate-900' => ! $tidakDiperlukan, 'text-slate-400' => $tidakDiperlukan])>{{ $p->label() }}</p>
                                @if ($p->pemutus)
                                    <p class="text-slate-500">
                                        {{ $p->status->label() }} oleh {{ $p->pemutus->name }} · @tanggal($p->diputuskan_pada, true)
                                    </p>
                                    @if ($p->catatan)
                                        <p class="mt-1 rounded-md bg-slate-50 px-2 py-1 text-slate-700">“{{ $p->catatan }}”</p>
                                    @endif
                                @elseif ($aktif)
                                    <p class="text-amber-700">Sedang menunggu keputusan</p>
                                @elseif ($tidakDiperlukan && $cuti->status !== \App\Enums\StatusCuti::Menunggu)
                                    <p class="text-slate-400">Tidak diperlukan</p>
                                @else
                                    <p class="text-slate-400">Menunggu tahap sebelumnya</p>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">Jenis cuti ini tanpa persetujuan: langsung disetujui.</li>
                    @endforelse
                </ol>
            </div>

            @if ($bisaBatal)
                <form method="POST" action="{{ route('leaves.cancel', $cuti) }}"
                      @submit="if (! confirm('Batalkan pengajuan {{ $cuti->nomor }}?')) $event.preventDefault()">
                    @csrf
                    <button type="submit" class="tombol-kedua w-full">Batalkan pengajuan</button>
                </form>
            @endif

            <a href="{{ route('leaves.index') }}" class="tombol-tautan inline-block">← Kembali ke daftar cuti</a>
        </div>
    </div>
</x-app-layout>
