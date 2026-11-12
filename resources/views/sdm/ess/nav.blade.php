{{--
    Navigasi Layanan Mandiri: tab di layar lebar, bar bawah tetap di ponsel. Item mengikuti izin.
    Pakai di atas isi setiap halaman ESS; sertakan juga ruang kosong di bawah (lihat akhir berkas ini).
--}}
@php
    $user = auth()->user();
    $menuEss = collect([
        ['route' => 'ess.index', 'label' => 'Beranda', 'icon' => 'home', 'can' => true],
        ['route' => 'ess.attendance', 'label' => 'Absensi', 'icon' => 'clock', 'can' => true],
        ['route' => 'ess.leaves', 'label' => 'Cuti', 'icon' => 'calendar-days', 'can' => true],
        ['route' => 'ess.overtime', 'label' => 'Lembur', 'icon' => 'moon', 'can' => $user->can('sdm.overtime.create')],
        ['route' => 'ess.reimbursements', 'label' => 'Klaim', 'icon' => 'receipt-percent', 'can' => $user->can('sdm.reimbursements.create')],
        ['route' => 'ess.payslips', 'label' => 'Slip gaji', 'icon' => 'banknotes', 'can' => true],
        ['route' => 'ess.profile', 'label' => 'Profil', 'icon' => 'user-circle', 'can' => true],
    ])->filter(fn ($m) => $m['can'])->values();
    $menuBawah = $menuEss->reject(fn ($m) => in_array($m['route'], ['ess.payslips', 'ess.profile'], true))->take(5);
@endphp

<nav class="mb-6 hidden gap-1 overflow-x-auto border-b border-slate-200 sm:flex" aria-label="Layanan Mandiri">
    @foreach ($menuEss as $m)
        <a href="{{ route($m['route']) }}" @class([
            '-mb-px inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
            'border-primary-600 text-primary-700' => request()->routeIs($m['route']),
            'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => ! request()->routeIs($m['route']),
        ]) @if (request()->routeIs($m['route'])) aria-current="page" @endif>
            <x-ui.icon :name="$m['icon']" class="h-4 w-4" /> {{ $m['label'] }}
        </a>
    @endforeach
</nav>

<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur sm:hidden" aria-label="Layanan Mandiri (ponsel)">
    <div class="grid" style="grid-template-columns: repeat({{ $menuBawah->count() }}, minmax(0, 1fr))">
        @foreach ($menuBawah as $m)
            <a href="{{ route($m['route']) }}" @class([
                'flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium',
                'text-primary-700' => request()->routeIs($m['route']),
                'text-slate-500' => ! request()->routeIs($m['route']),
            ]) @if (request()->routeIs($m['route'])) aria-current="page" @endif>
                <x-ui.icon :name="$m['icon']" class="h-6 w-6" />
                {{ $m['label'] }}
            </a>
        @endforeach
    </div>
</nav>
