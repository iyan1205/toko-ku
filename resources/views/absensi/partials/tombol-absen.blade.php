{{--
    Tombol absen masuk/pulang dengan lokasi GPS dan selfie (halaman Absensi & Layanan Mandiri).
    $aksi: 'masuk' | 'pulang'. $aturan: AbsensiService::aturanAbsen(). $kembali: 'ess' untuk kembali ke Layanan Mandiri.
--}}
@php
    $masuk = $aksi === 'masuk';
    $wajibLokasi = $masuk && $aturan['lokasi'];
@endphp

<form method="POST" action="{{ route($masuk ? 'attendances.clock-in' : 'attendances.clock-out') }}" enctype="multipart/form-data"
      x-data="absen({ wajibLokasi: @js($wajibLokasi), wajibFoto: @js($aturan['foto']) })" x-on:submit="kirim($event)" class="grid gap-2 sm:justify-items-end">
    @csrf
    @isset($kembali)
        <input type="hidden" name="kembali" value="{{ $kembali }}">
    @endisset
    <input type="hidden" name="lokasi" x-bind:value="lokasi">

    @if ($aturan['foto'])
        <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-sm text-slate-600 hover:border-primary-400">
            <template x-if="pratinjau"><img x-bind:src="pratinjau" alt="Pratinjau selfie" class="h-12 w-12 rounded-full object-cover"></template>
            <x-ui.icon name="camera" class="h-5 w-5 text-slate-400" x-show="! pratinjau" />
            <span x-text="pratinjau ? 'Ganti foto' : 'Ambil selfie'"></span>
            <input type="file" name="foto" accept="image/*" capture="user" class="sr-only" x-on:change="pilihFoto($event)">
        </label>
    @endif

    <x-ui.button type="submit" :variant="$masuk ? 'primary' : 'danger'" :icon="$masuk ? 'arrow-right-end-on-rectangle' : 'arrow-left-start-on-rectangle'"
                 class="w-full justify-center py-3 sm:w-auto" x-bind:disabled="mengirim">{{ $masuk ? 'Absen masuk' : 'Absen pulang' }}</x-ui.button>

    <p class="text-xs text-slate-500" aria-live="polite">
        <span x-show="statusLokasi === 'mencari'">Mencari lokasi…</span>
        <span x-show="statusLokasi === 'ok'" x-cloak>Lokasi terdeteksi</span>
        <span x-show="statusLokasi === 'gagal'" x-cloak>
            Lokasi tidak terbaca.
            <button type="button" class="font-medium text-primary-700 hover:underline" x-on:click="cariLokasi()">Coba lagi</button>
        </span>
        @if ($wajibLokasi)
            <span class="block">Absen hanya dalam radius {{ $aturan['cabang']->attendance_radius_m }} m dari {{ $aturan['cabang']->name }}.</span>
        @endif
    </p>
    <p class="text-sm text-warning-700" x-show="galat" x-text="galat" x-cloak></p>
</form>
