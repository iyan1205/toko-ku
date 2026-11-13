{{-- Absensi saya per bulan: ringkasan status dan daftar harian. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Absensi Saya</x-slot>
    <x-slot name="description">{{ $month->translatedFormat('F Y') }}</x-slot>

    @include('sdm.ess.nav')

    <div class="mb-4 flex items-center justify-between gap-2">
        <x-ui.button variant="secondary" size="sm" icon="chevron-left" :href="route('ess.attendance', ['bulan' => $month->subMonthNoOverflow()->format('Y-m')])">{{ $month->subMonthNoOverflow()->translatedFormat('M Y') }}</x-ui.button>
        <span class="text-sm font-semibold text-slate-900">{{ $month->translatedFormat('F Y') }}</span>
        @if ($month->addMonthNoOverflow()->lte(now()->startOfMonth()))
            <x-ui.button variant="secondary" size="sm" icon-right="chevron-right" :href="route('ess.attendance', ['bulan' => $month->addMonthNoOverflow()->format('Y-m')])">{{ $month->addMonthNoOverflow()->translatedFormat('M Y') }}</x-ui.button>
        @else
            <span class="w-24"></span>
        @endif
    </div>

    <div class="mb-6 flex flex-wrap gap-2">
        @foreach ($summary as $label => $jumlah)
            <span @class(['rounded-full px-3 py-1 text-xs font-medium', 'bg-slate-100 text-slate-500' => $jumlah === 0, 'bg-primary-50 text-primary-700' => $jumlah > 0])>{{ $label }}: {{ $jumlah }}</span>
        @endforeach
    </div>

    @php
        $warna = ['present' => 'success', 'late' => 'warning', 'permit' => 'info', 'sick' => 'info', 'leave' => 'primary', 'unpaid_leave' => 'primary', 'absent' => 'danger'];
        $bukaKoreksi = $errors->hasAny(['attendance_date', 'clock_in', 'clock_out', 'reason']);
    @endphp

    <div class="grid gap-6 lg:grid-cols-3" x-data="{ koreksi: @js($bukaKoreksi), tanggal: @js(old('attendance_date', today()->toDateString())), masuk: @js(old('clock_in', '')), pulang: @js(old('clock_out', '')),
                                                     isi(t, m, p) { this.tanggal = t; this.masuk = m; this.pulang = p; this.koreksi = true; this.$nextTick(() => this.$refs.formKoreksi?.scrollIntoView({ behavior: 'smooth', block: 'start' })) } }">
        <x-ui.table :sticky="false" class="lg:col-span-2">
            <x-slot:head>
                <x-ui.table.th>Tanggal</x-ui.table.th>
                <x-ui.table.th>Status</x-ui.table.th>
                <x-ui.table.th align="right">Masuk</x-ui.table.th>
                <x-ui.table.th align="right">Pulang</x-ui.table.th>
                @if ($canCorrect)
                    <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
                @endif
            </x-slot:head>
            @forelse ($days as $key => $d)
                <tr @class(['bg-slate-50/70' => in_array($d['category'], ['off', 'holiday'], true)])>
                    <x-ui.table.td numeric class="whitespace-nowrap">{{ Format::tanggal($d['date']) }}
                        <p class="text-xs text-slate-500">{{ $d['date']->locale('id')->translatedFormat('l') }}{{ $d['shift'] ? ' · '.$d['shift']->nama : '' }}</p>
                    </x-ui.table.td>
                    <x-ui.table.td>
                        @if (isset($warna[$d['category']]))
                            <x-ui.badge :color="$warna[$d['category']]">{{ \App\Services\Sdm\AttendanceRecapService::CATEGORIES[$d['category']] }}</x-ui.badge>
                        @else
                            <span class="text-xs text-slate-500">{{ $d['holiday'] ?? \App\Services\Sdm\AttendanceRecapService::CATEGORIES[$d['category']] }}</span>
                        @endif
                        @if ($d['record']?->late_minutes)
                            <span class="block text-xs text-warning-700">telat {{ $d['record']->late_minutes }} mnt</span>
                        @endif
                        @if ($d['leave'] ?? $d['record']?->keterangan)
                            <p class="text-xs text-slate-500">{{ $d['leave'] ?? $d['record']->keterangan }}</p>
                        @endif
                    </x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $d['record']?->clock_in?->format('H:i') ?? '—' }}</x-ui.table.td>
                    <x-ui.table.td align="right" numeric>{{ $d['record']?->clock_out?->format('H:i') ?? '—' }}</x-ui.table.td>
                    @if ($canCorrect)
                        <x-ui.table.td align="right">
                            @if (in_array($d['category'], ['present', 'late', 'absent'], true) || ($d['record'] === null && $d['category'] !== 'not_employed'))
                                <button type="button" class="text-xs font-medium text-primary-700 hover:underline"
                                        x-on:click="isi(@js($key), @js($d['record']?->clock_in?->format('H:i') ?? ''), @js($d['record']?->clock_out?->format('H:i') ?? ''))">Koreksi</button>
                            @endif
                        </x-ui.table.td>
                    @endif
                </tr>
            @empty
                <x-ui.table.empty :colspan="$canCorrect ? 5 : 4" icon="clock" title="Belum ada hari yang lewat di bulan ini" />
            @endforelse
        </x-ui.table>

        @if ($canCorrect)
            <div class="grid content-start gap-6">
                <form method="POST" action="{{ route('ess.corrections.store') }}" x-ref="formKoreksi" x-data="{ kirim: false }" x-on:submit="kirim = true">
                    @csrf
                    <x-ui.card title="Koreksi absensi" description="Lupa absen atau jam tercatat salah? Ajukan jam yang benar; berlaku setelah disetujui.">
                        <div x-show="! koreksi">
                            <x-ui.button type="button" variant="secondary" icon="pencil-square" class="w-full justify-center" x-on:click="koreksi = true">Ajukan koreksi</x-ui.button>
                        </div>
                        <div class="grid gap-4" x-show="koreksi" x-cloak>
                            <x-ui.input name="attendance_date" label="Tanggal" type="date" required x-model="tanggal" :max="today()->toDateString()" />
                            <div class="grid grid-cols-2 gap-4">
                                <x-ui.input name="clock_in" label="Jam masuk" type="time" x-model="masuk" />
                                <x-ui.input name="clock_out" label="Jam pulang" type="time" x-model="pulang" />
                            </div>
                            <p class="-mt-2 text-xs text-slate-500">Kosongkan yang tidak perlu dikoreksi.</p>
                            <x-ui.textarea name="reason" label="Alasan" rows="2" maxlength="500" required :value="old('reason')" placeholder="Misal lupa absen pulang karena rapat di luar" />
                            <x-ui.button type="submit" icon="paper-airplane" class="justify-center" x-bind:disabled="kirim">Ajukan koreksi</x-ui.button>
                        </div>
                    </x-ui.card>
                </form>

                @if ($corrections->isNotEmpty())
                    <x-ui.card title="Koreksi terakhir">
                        <ul class="grid gap-3 text-sm">
                            @foreach ($corrections as $c)
                                <li class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-medium text-slate-900">{{ Format::tanggal($c->attendance_date) }} · {{ $c->requestedLabel() }}</p>
                                        <p class="text-xs text-slate-500">{{ $c->number }}{{ $c->is_rejected && $c->cancel_reason ? ' · '.$c->cancel_reason : '' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <x-ui.badge :color="$c->statusColor()" dot>{{ $c->statusLabel() }}</x-ui.badge>
                                        @if ($c->isPending())
                                            <form method="POST" action="{{ route('ess.corrections.cancel', $c->id) }}">
                                                @csrf
                                                <button type="submit" class="mt-1 text-xs font-medium text-danger-600 hover:underline">Batalkan</button>
                                            </form>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </x-ui.card>
                @endif
            </div>
        @endif
    </div>

    @include('sdm.ess.spacer')
</x-app-layout>
