{{-- Pengaturan SDM: aturan absensi bawaan (bila shift tidak mengaturnya sendiri) dan aturan upah lembur bertanggal berlaku. --}}
@use('App\Models\OvertimeRule')
@use('App\Support\Format')
@php
    $bolehUbah = auth()->user()->can('settings.sdm.update');
@endphp

<x-app-layout>
    <x-slot name="header">SDM</x-slot>
    <x-slot name="description">Hari kerja, jam kerja bawaan, toleransi absen, selfie, periode rekap, dan aturan upah lembur.</x-slot>

    <form method="POST" action="{{ route('settings.sdm.update') }}" class="grid gap-8">
        @csrf
        @method('PUT')

        <fieldset @disabled(! $bolehUbah) class="grid gap-8">
            <x-ui.form-section title="Hari kerja" description="Dipakai untuk menghitung hari kerja, alpha, dan hari libur mingguan bila karyawan tidak punya jadwal pada hari itu.">
                <x-ui.select name="workweek" label="Hari kerja per minggu" :options="[6 => '6 hari (Senin–Sabtu, libur Minggu)', 5 => '5 hari (Senin–Jumat, libur Sabtu & Minggu)']"
                             :selected="$values['workweek']" required />
            </x-ui.form-section>

            <x-ui.form-section title="Jam kerja bawaan" description="Untuk hari kerja tanpa shift di jadwal. Kosongkan bila semua karyawan memakai jadwal shift.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="default_in" label="Jam masuk" type="time" :value="$values['default_in']" />
                    <x-ui.input name="default_out" label="Jam pulang" type="time" :value="$values['default_out']" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section title="Toleransi absen" description="Bawaan untuk semua shift. Shift yang mengisi toleransinya sendiri memakai angka di shift.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="late_tolerance" label="Toleransi telat" type="number" min="0" max="240" suffix="menit" :value="$values['late_tolerance']" required
                                hint="Masuk lewat jam masuk + toleransi tercatat Terlambat. Menit telat dihitung dari jam masuk." />
                    <x-ui.input name="early_tolerance" label="Toleransi pulang cepat" type="number" min="0" max="240" suffix="menit" :value="$values['early_tolerance']" required />
                </div>
            </x-ui.form-section>

            <x-ui.form-section title="Absen dari ponsel" description="Radius lokasi diatur per cabang di Pengaturan → Cabang.">
                <x-ui.checkbox name="require_selfie" label="Wajib foto selfie saat absen masuk dan pulang" :checked="$values['require_selfie']" unchecked-value="0"
                               hint="Foto disimpan di server (bukan folder publik) dan hanya bisa dibuka oleh karyawan itu dan SDM." />
            </x-ui.form-section>

            <x-ui.form-section title="Koreksi & rekap" description="Rekap absensi dan penggajian memakai periode yang sama.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="correction_days" label="Batas koreksi absensi" type="number" min="0" max="90" suffix="hari" :value="$values['correction_days']" required
                                hint="Karyawan bisa mengajukan koreksi sampai sekian hari ke belakang." />
                    <x-ui.input name="period_start_day" label="Awal periode absensi" type="number" min="1" max="28" suffix="tanggal" :value="$values['period_start_day']" required
                                hint="1 = tanggal 1 sampai akhir bulan. 26 = periode November dihitung 26 Okt – 25 Nov." />
                </div>

                @if ($bolehUbah)
                    <x-slot:footer>
                        <x-ui.button type="submit">Simpan pengaturan SDM</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.form-section>
        </fieldset>
    </form>

    <div class="mt-8 grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-ui.table :sticky="false">
                <x-slot:toolbar>
                    <div>
                        <h2 class="text-sm font-semibold text-slate-900">Aturan upah lembur</h2>
                        <p class="text-sm text-slate-500">Lembur dihitung dengan aturan yang berlaku pada tanggal lemburnya. Bila aturan berubah, tambahkan baris baru; jangan hapus baris lama yang sudah dipakai.</p>
                    </div>
                </x-slot:toolbar>
                <x-slot:head>
                    <x-ui.table.th>Berlaku mulai</x-ui.table.th>
                    <x-ui.table.th>Cara hitung</x-ui.table.th>
                    <x-ui.table.th>Tingkat</x-ui.table.th>
                    <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                </x-slot:head>
                @foreach ($overtimeRules as $r)
                    <tr class="align-top">
                        <x-ui.table.td class="whitespace-nowrap">{{ Format::tanggal($r->effective_from) }} @if ($loop->first)<x-ui.badge color="success" class="ml-1">Terbaru</x-ui.badge>@endif
                            @if ($r->notes)<span class="block text-xs text-slate-500">{{ $r->notes }}</span>@endif
                        </x-ui.table.td>
                        <x-ui.table.td>
                            @if ($r->mode === 'flat')
                                {{ Format::rupiah($r->flat_rate) }} per jam
                            @else
                                Gaji pokok ÷ {{ $r->divisor }} × pengali
                            @endif
                            @if ($r->rounding_minutes || $r->min_minutes)
                                <span class="block text-xs text-slate-500">{{ $r->rounding_minutes ? 'dibulatkan per '.$r->rounding_minutes.' menit' : '' }}{{ $r->rounding_minutes && $r->min_minutes ? ' · ' : '' }}{{ $r->min_minutes ? 'minimal '.$r->min_minutes.' menit' : '' }}</span>
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td class="text-xs text-slate-600">
                            @if ($r->mode === 'multiplier')
                                <p><span class="font-medium text-slate-800">Hari kerja:</span> {{ OvertimeRule::tiersLabel($r->workday_tiers) }}</p>
                                <p><span class="font-medium text-slate-800">Libur (6 hari kerja):</span> {{ OvertimeRule::tiersLabel($r->rest_day_tiers_6) }}</p>
                                <p><span class="font-medium text-slate-800">Libur (5 hari kerja):</span> {{ OvertimeRule::tiersLabel($r->rest_day_tiers_5) }}</p>
                            @else
                                —
                            @endif
                        </x-ui.table.td>
                        <x-ui.table.td align="right">
                            @if ($bolehUbah && $overtimeRules->count() > 1)
                                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" aria-label="Hapus aturan {{ Format::tanggal($r->effective_from) }}" x-data
                                    x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('settings.sdm.overtime-rules.destroy', $r)) }}, nama: {{ \Illuminate\Support\Js::from('aturan lembur mulai '.Format::tanggal($r->effective_from)) }} })" />
                            @endif
                        </x-ui.table.td>
                    </tr>
                @endforeach
            </x-ui.table>
        </div>

        @if ($bolehUbah)
            @php
                $terbaru = $overtimeRules->first();
            @endphp
            <form method="POST" action="{{ route('settings.sdm.overtime-rules.store') }}" x-data="{ mode: @js(old('mode', $terbaru?->mode ?? 'multiplier')) }">
                @csrf
                <x-ui.form-section title="Aturan lembur baru" description="Tingkat ditulis jam:pengali, dipisah titik koma; * = sisa jam. Contoh hari kerja: 1:1,5; *:2.">
                    <x-ui.input name="effective_from" label="Berlaku mulai" type="date" required :value="today()->toDateString()" />
                    <x-ui.select name="mode" label="Cara hitung" required :options="OvertimeRule::MODES" :selected="$terbaru?->mode" x-model="mode" />
                    <div class="grid gap-4" x-show="mode === 'multiplier'">
                        <x-ui.input name="divisor" label="Pembagi upah sejam" type="number" min="1" max="400" :value="$terbaru?->divisor ?? 173" hint="Upah sejam = gaji pokok ÷ pembagi." />
                        <x-ui.input name="workday_tiers" label="Hari kerja" :value="OvertimeRule::tiersInput($terbaru?->workday_tiers)" maxlength="100" />
                        <x-ui.input name="rest_day_tiers_6" label="Hari libur (6 hari kerja)" :value="OvertimeRule::tiersInput($terbaru?->rest_day_tiers_6)" maxlength="100" />
                        <x-ui.input name="rest_day_tiers_5" label="Hari libur (5 hari kerja)" :value="OvertimeRule::tiersInput($terbaru?->rest_day_tiers_5)" maxlength="100" />
                    </div>
                    <div x-show="mode === 'flat'" x-cloak>
                        <x-ui.input name="flat_rate" label="Tarif per jam" type="number" min="0" step="any" prefix="Rp" :value="$terbaru?->flat_rate" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <x-ui.input name="rounding_minutes" label="Bulatkan per" type="number" min="0" max="60" suffix="menit" :value="$terbaru?->rounding_minutes ?? 0" hint="0 = tanpa pembulatan." />
                        <x-ui.input name="min_minutes" label="Lembur minimal" type="number" min="0" max="240" suffix="menit" :value="$terbaru?->min_minutes ?? 0" />
                    </div>
                    <x-ui.input name="notes" label="Catatan" maxlength="255" placeholder="Dasar aturan, misal PP 35/2021" />
                    <x-slot:footer><x-ui.button type="submit">Simpan aturan</x-ui.button></x-slot:footer>
                </x-ui.form-section>
            </form>
        @endif
    </div>
</x-app-layout>
