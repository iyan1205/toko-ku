{{-- Pengaturan → Sistem: status scheduler, queue worker, antrean, email, dan batas unggah. --}}
@use('App\Support\Format')
@php
    $waktu = fn ($t) => $t ? Format::tanggal($t, true).' ('.$t->locale('id')->diffForHumans().')' : 'belum pernah';
    $bolehUbah = auth()->user()->can('settings.system.update');
@endphp

<x-app-layout>
    <x-slot name="header">Sistem</x-slot>
    <x-slot name="description">Proses latar belakang: jadwal harian (backup, pembersihan) dan antrean (email, ekspor besar). Cara memasangnya ada di docs/DEPLOY.md.</x-slot>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Scheduler (jadwal tugas)">
            <x-slot:actions>
                <x-ui.badge :color="$scheduler['alive'] ? 'success' : 'danger'" dot>{{ $scheduler['alive'] ? 'Berjalan' : 'Tidak berjalan' }}</x-ui.badge>
            </x-slot:actions>
            <p class="text-sm text-slate-600">Terakhir aktif: {{ $waktu($scheduler['seen']) }}.</p>
            <p class="mt-2 text-sm text-slate-600">Menjalankan backup database harian, pembersihan log, hasil impor, berkas ekspor kedaluwarsa, dan notifikasi lama.</p>
            @unless ($scheduler['alive'])
                <p class="mt-3 text-sm font-medium text-slate-900">Pasang cron untuk pengguna web server:</p>
                <pre class="mt-1 overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs text-slate-100">* * * * * cd {{ $basePath }} &amp;&amp; {{ $phpBinary }} artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
            @endunless
        </x-ui.card>

        <x-ui.card title="Queue worker (antrean)">
            <x-slot:actions>
                <x-ui.badge :color="$worker['alive'] ? 'success' : ($connection === 'sync' ? 'neutral' : 'danger')" dot>
                    {{ $connection === 'sync' ? 'Tanpa antrean (sync)' : ($worker['alive'] ? 'Berjalan' : 'Tidak berjalan') }}
                </x-ui.badge>
            </x-slot:actions>
            <dl class="grid gap-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Koneksi</dt><dd class="font-mono text-slate-900">{{ $connection }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Terakhir aktif</dt><dd class="text-right text-slate-900">{{ $waktu($worker['seen']) }}</dd></div>
                @if ($pendingJobs !== null)
                    <div class="flex justify-between gap-4"><dt class="text-slate-500">Job menunggu</dt><dd class="tabular-nums text-slate-900">{{ Format::jumlah($pendingJobs) }}</dd></div>
                @endif
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Job gagal</dt><dd class="tabular-nums text-slate-900">{{ Format::jumlah($failedCount) }}</dd></div>
            </dl>
            @if (! $worker['alive'] && $connection !== 'sync')
                <x-ui.alert type="warning" class="mt-4">
                    Email notifikasi menunggu di antrean sampai worker dijalankan. Ekspor besar tetap dibuat langsung (tanpa antrean) selama worker belum berjalan.
                    Jalankan worker sebagai layanan systemd (lihat docs/DEPLOY.md).
                </x-ui.alert>
            @endif
        </x-ui.card>

        <x-ui.card title="Email">
            <dl class="grid gap-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">Pengirim (MAIL_MAILER)</dt><dd class="font-mono text-slate-900">{{ $mailer }}</dd></div>
            </dl>
            @if (in_array($mailer, ['log', 'array'], true))
                <x-ui.alert type="info" class="mt-4">Email belum benar-benar dikirim: isinya hanya ditulis ke log aplikasi. Atur SMTP di .env server untuk mengirim email notifikasi.</x-ui.alert>
            @endif
        </x-ui.card>

        <x-ui.card title="Unggah berkas">
            <p class="text-sm text-slate-600">Batas ukuran lampiran saat ini <span class="font-semibold text-slate-900">{{ Format::jumlah($uploadMb) }} MB</span> per berkas
                (terkecil dari pengaturan aplikasi dan upload_max_filesize/post_max_size PHP).</p>
        </x-ui.card>
    </div>

    <x-ui.table :sticky="false" class="mt-8">
        <x-slot:toolbar>
            <div class="flex w-full flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-900">Job gagal terakhir</h2>
                @if ($bolehUbah && $failedCount > 0)
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('settings.system.flush') }}">
                            @csrf
                            <x-ui.button type="submit" variant="ghost" size="sm" icon="trash">Kosongkan</x-ui.button>
                        </form>
                        <form method="POST" action="{{ route('settings.system.retry') }}">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm" icon="arrow-path">Coba lagi semua</x-ui.button>
                        </form>
                    </div>
                @endif
            </div>
        </x-slot:toolbar>
        <x-slot:head>
            <x-ui.table.th>Job</x-ui.table.th>
            <x-ui.table.th>Kesalahan</x-ui.table.th>
            <x-ui.table.th>Waktu</x-ui.table.th>
        </x-slot:head>
        @forelse ($failedJobs as $job)
            <tr>
                <x-ui.table.td><span class="font-mono text-xs">{{ class_basename(json_decode($job->payload, true)['displayName'] ?? '-') }}</span></x-ui.table.td>
                <x-ui.table.td muted><span class="block max-w-xl truncate" title="{{ \Illuminate\Support\Str::limit($job->exception, 500) }}">{{ \Illuminate\Support\Str::before($job->exception, "\n") }}</span></x-ui.table.td>
                <x-ui.table.td muted>{{ Format::tanggal($job->failed_at, true) }}</x-ui.table.td>
            </tr>
        @empty
            <x-ui.table.empty colspan="3" icon="check-circle" title="Tidak ada job yang gagal" />
        @endforelse
    </x-ui.table>
</x-app-layout>
