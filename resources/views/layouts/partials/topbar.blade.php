{{--
    Topbar layouts.app: tombol menu/ciut, breadcrumb, pencarian global, cabang, notifikasi, profil.
--}}
@php
    $user = auth()->user();
@endphp

<header class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:gap-3 sm:px-6 print:hidden">
    {{-- Layar kecil: buka drawer --}}
    <button type="button" x-on:click="sidebarOpen = true"
            class="-ml-1 rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 lg:hidden"
            aria-label="Buka menu">
        <x-ui.icon name="bars-3" class="h-6 w-6" />
    </button>

    {{-- Layar lebar: ciutkan/lebarkan sidebar --}}
    <button type="button" x-on:click="ciutkan(! ciut)"
            class="-ml-2 hidden rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 lg:inline-flex"
            x-bind:aria-label="ciut ? 'Lebarkan sidebar' : 'Ciutkan sidebar'" x-bind:title="ciut ? 'Lebarkan sidebar' : 'Ciutkan sidebar'">
        <x-ui.icon name="chevron-double-left" variant="m" class="h-5 w-5 transition-transform" x-bind:class="ciut && 'rotate-180'" />
    </button>

    <x-ui.breadcrumb :items="$breadcrumb" class="hidden min-w-0 md:block" />

    <div class="ml-auto flex items-center gap-1 sm:gap-2">
        {{-- Pencarian global. Tekan "/" untuk fokus. --}}
        <div x-data="cariGlobal(@js(route('search')))"
             x-on:keydown.window="pintasan($event)"
             x-on:click.outside="tutup()"
             class="relative">
            <button type="button" x-on:click="bukaMobile()"
                    class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 md:hidden"
                    aria-label="Cari">
                <x-ui.icon name="magnifying-glass" class="h-5 w-5" />
            </button>

            <div class="hidden md:block"
                 x-bind:class="mobile && '!block fixed inset-x-0 top-16 z-30 border-b border-slate-200 bg-white p-3 md:static md:border-0 md:p-0'">
                <div class="relative">
                    <label for="cari-global" class="sr-only">Cari produk, transaksi, pelanggan</label>
                    <x-ui.icon name="magnifying-glass" variant="m" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input id="cari-global" x-ref="input" type="search" autocomplete="off"
                           x-model="q"
                           x-on:input.debounce.250ms="cari()"
                           x-on:focus="if (q.trim() !== '') buka = true"
                           x-on:keydown.down.prevent="pindah(1)"
                           x-on:keydown.up.prevent="pindah(-1)"
                           x-on:keydown.enter.prevent="pilih()"
                           x-on:keydown.escape="tutup(); $el.blur()"
                           role="combobox" aria-autocomplete="list" aria-controls="hasil-cari-global"
                           x-bind:aria-expanded="buka.toString()"
                           placeholder="Cari produk, transaksi…"
                           class="block w-full rounded-lg border-slate-200 bg-slate-50 py-1.5 pl-9 pr-9 text-sm placeholder:text-slate-400 focus:border-primary-500 focus:bg-white focus:ring-1 focus:ring-primary-500 md:w-64 lg:w-80">
                    <kbd class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-slate-200 bg-white px-1.5 text-[10px] font-medium text-slate-400 md:block">/</kbd>
                </div>

                <div id="hasil-cari-global" x-show="buka" x-cloak x-transition.opacity.duration.100ms role="listbox"
                     class="absolute inset-x-3 z-40 mt-2 max-h-[70vh] overflow-y-auto rounded-xl bg-white py-1 shadow-float ring-1 ring-slate-900/5 md:inset-x-auto md:right-0 md:w-[28rem]">
                    <p x-show="memuat" class="px-4 py-3 text-sm text-slate-500">Mencari…</p>
                    <p x-show="! memuat && q.trim().length < 2" class="px-4 py-3 text-sm text-slate-500">Ketik minimal 2 huruf.</p>
                    <p x-show="! memuat && gagal" class="px-4 py-3 text-sm text-danger-600">Pencarian gagal. Coba lagi.</p>
                    <p x-show="! memuat && ! gagal && q.trim().length >= 2 && hasil.length === 0" class="px-4 py-3 text-sm text-slate-500">
                        Tidak ada hasil untuk “<span class="font-medium text-slate-700" x-text="q.trim()"></span>”.
                    </p>
                    <template x-for="kelompok in hasil" :key="kelompok.label">
                        <div class="py-1" x-show="! memuat">
                            <p class="px-4 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-slate-400" x-text="kelompok.label"></p>
                            <template x-for="item in kelompok.items" :key="item.url">
                                <a x-bind:href="item.url" role="option"
                                   x-bind:aria-selected="(urutan(item) === aktif).toString()"
                                   x-on:mouseenter="aktif = urutan(item)"
                                   x-bind:class="urutan(item) === aktif ? 'bg-slate-100' : ''"
                                   class="block px-4 py-2 focus:outline-none">
                                    <span class="block truncate text-sm font-medium text-slate-900" x-text="item.title"></span>
                                    <span class="block truncate text-xs text-slate-500" x-text="item.subtitle"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{--
            Cabang aktif (disimpan di session): data transaksi disaring ke cabang ini (BranchScope).
            Daftar berisi cabang aktif yang boleh diakses; "Semua cabang" bila lebih dari satu.
        --}}
        @if ($branchOptions->isNotEmpty())
            @php
                $namaCabang = $currentBranch?->name ?? 'Semua cabang';
            @endphp
            <x-ui.dropdown class="hidden xl:inline-block" width="w-64">
                <x-slot:trigger>
                    <button type="button" x-bind:aria-expanded="open" aria-label="Cabang aktif: {{ $namaCabang }}"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
                        <x-ui.icon name="building-office-2" variant="m" class="h-4 w-4 text-slate-400" />
                        <span class="max-w-[10rem] truncate">{{ $namaCabang }}</span>
                        <x-ui.icon name="chevron-down" variant="m" class="h-4 w-4 text-slate-400" />
                    </button>
                </x-slot:trigger>
                <div class="px-4 pb-1 pt-3">
                    <p class="text-xs font-medium text-slate-500">Cabang aktif</p>
                    <p class="mt-0.5 truncate text-xs text-slate-400">{{ $storeName }}</p>
                </div>
                <div class="py-1">
                    @if ($canChooseAllBranches)
                        <form method="POST" action="{{ route('current-branch.update') }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="branch_id" value="semua">
                            <button type="submit" role="menuitem" @disabled($currentBranch === null)
                                    class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none disabled:cursor-default disabled:hover:bg-transparent">
                                <span class="grid h-4 w-4 place-items-center">
                                    @if ($currentBranch === null)
                                        <x-ui.icon name="check" variant="m" class="h-4 w-4 text-primary-600" />
                                    @endif
                                </span>
                                <span @class(['min-w-0 flex-1 truncate', 'font-medium text-slate-900' => $currentBranch === null])>Semua cabang</span>
                            </button>
                        </form>
                    @endif
                    @foreach ($branchOptions as $branch)
                        <form method="POST" action="{{ route('current-branch.update') }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="branch_id" value="{{ $branch->id }}">
                            <button type="submit" role="menuitem" @disabled($currentBranch && $branch->is($currentBranch))
                                    class="flex w-full items-center gap-2 px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 focus:bg-slate-50 focus:outline-none disabled:cursor-default disabled:hover:bg-transparent">
                                <span class="grid h-4 w-4 place-items-center">
                                    @if ($currentBranch && $branch->is($currentBranch))
                                        <x-ui.icon name="check" variant="m" class="h-4 w-4 text-primary-600" />
                                    @endif
                                </span>
                                <span @class(['min-w-0 flex-1 truncate', 'font-medium text-slate-900' => $currentBranch && $branch->is($currentBranch)])>{{ $branch->name }}</span>
                                @if ($branch->is_default)
                                    <span class="text-[11px] text-slate-400">Utama</span>
                                @endif
                            </button>
                        </form>
                    @endforeach
                </div>
                @can('settings.branches.view')
                    <div class="border-t border-slate-100 px-4 py-2.5">
                        <a href="{{ route('settings.branches.index') }}" class="text-sm font-medium text-primary-600 hover:text-primary-700">Kelola cabang</a>
                    </div>
                @endcan
            </x-ui.dropdown>
        @endif

        {{--
            Lonceng: notifikasi pengguna (persetujuan, hasil persetujuan, ekspor) dan, bagi yang boleh
            melihat stok, produk yang perlu diisi ulang. Daftar diambil saat lonceng dibuka.
        --}}
        @php
            $labelLonceng = collect([
                $unreadNotifications > 0 ? $unreadNotifications.' notifikasi belum dibaca' : null,
                $restockCount ? $restockCount.' produk perlu diisi ulang' : null,
            ])->filter()->implode(', ');
            $angkaLonceng = $unreadNotifications + ($restockCount ?? 0);
        @endphp
        <div x-data="pusatNotifikasi({{ \Illuminate\Support\Js::from([
                'feedUrl' => route('notifications.feed'),
                'stokUrl' => $restockCount ? route('notifications') : null,
                'bacaSemuaUrl' => route('notifications.read-all'),
                'belumDibaca' => $unreadNotifications,
            ]) }})">
            <x-ui.dropdown width="w-80 sm:w-96">
                <x-slot:trigger>
                    <button type="button" x-bind:aria-expanded="open" x-on:click="ambil()"
                            class="relative rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                            aria-label="Notifikasi{{ $labelLonceng !== '' ? ': '.$labelLonceng : '' }}">
                        <x-ui.icon name="bell" class="h-5 w-5" />
                        <span @class(['absolute right-1 top-1 grid h-4 min-w-[1rem] place-items-center rounded-full bg-danger-500 px-1 text-[10px] font-semibold leading-none text-white'])
                              x-show="belumDibaca + {{ (int) ($restockCount ?? 0) }} > 0" @if ($angkaLonceng === 0) x-cloak @endif
                              x-text="Math.min(belumDibaca + {{ (int) ($restockCount ?? 0) }}, 99)">{{ min($angkaLonceng, 99) }}</span>
                    </button>
                </x-slot:trigger>

                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <p class="text-sm font-semibold text-slate-900">Notifikasi</p>
                    <button type="button" x-show="belumDibaca > 0" x-cloak x-on:click="bacaSemua()"
                            class="text-xs font-medium text-primary-600 hover:text-primary-700">Tandai semua dibaca</button>
                </div>

                <div class="max-h-[60vh] overflow-y-auto">
                    <div x-show="memuat" class="px-4 py-3"><x-ui.skeleton lines="3" /></div>
                    <p x-show="gagal" x-cloak class="px-4 py-3 text-sm text-danger-600">Gagal memuat. Coba buka lagi.</p>
                    <p x-show="termuat && items.length === 0" x-cloak class="px-4 py-6 text-center text-sm text-slate-500">Belum ada notifikasi untuk Anda.</p>
                    <template x-for="item in items" :key="item.id">
                        <a x-bind:href="item.url" role="menuitem"
                           class="flex items-start gap-3 px-4 py-2.5 text-sm hover:bg-slate-50 focus:bg-slate-50 focus:outline-none"
                           x-bind:class="item.read ? '' : 'bg-primary-50/40'">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" x-bind:class="item.read ? 'bg-transparent' : 'bg-primary-500'"></span>
                            <span class="min-w-0">
                                <span class="block truncate text-slate-900" x-bind:class="item.read ? 'font-medium' : 'font-semibold'" x-text="item.title"></span>
                                <span class="line-clamp-2 block text-xs text-slate-500" x-text="item.body"></span>
                                <span class="mt-0.5 block text-[11px] text-slate-400" x-text="item.time"></span>
                            </span>
                        </a>
                    </template>

                    @if ($restockCount)
                        <div class="mt-1 border-t border-slate-100">
                            <p class="px-4 pb-1 pt-3 text-xs font-medium text-slate-500">Stok menipis atau habis · {{ $restockCount }} produk</p>
                            <template x-for="item in stok" :key="item.url">
                                <a x-bind:href="item.url" role="menuitem"
                                   class="flex items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-slate-50 focus:bg-slate-50 focus:outline-none">
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-slate-900" x-text="item.title"></span>
                                        <span class="block truncate text-xs text-slate-500" x-text="item.subtitle"></span>
                                    </span>
                                    <span x-show="item.status === 'habis'"><x-ui.badge color="danger">Habis</x-ui.badge></span>
                                    <span x-show="item.status === 'menipis'"><x-ui.badge color="warning">Menipis</x-ui.badge></span>
                                </a>
                            </template>
                            <a href="{{ route('stock.index', ['stok' => 'menipis']) }}" class="block px-4 py-2 text-sm font-medium text-primary-600 hover:text-primary-700">Lihat semua stok menipis</a>
                        </div>
                    @endif
                </div>

                <div class="border-t border-slate-100 px-4 py-2.5">
                    <a href="{{ route('notifications.index') }}" class="text-sm font-medium text-primary-600 hover:text-primary-700">Lihat semua notifikasi</a>
                </div>
            </x-ui.dropdown>
        </div>

        {{-- Profil & keluar --}}
        <x-ui.dropdown width="w-64">
            <x-slot:trigger>
                <button type="button" x-bind:aria-expanded="open"
                        class="flex items-center gap-2 rounded-lg p-1 pr-2 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                        aria-label="Menu akun {{ $user->name }}">
                    <span class="grid h-8 w-8 place-items-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700" aria-hidden="true">
                        {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                    </span>
                    <span class="hidden text-left sm:block">
                        <span class="block max-w-[9rem] truncate text-sm font-medium leading-tight text-slate-900">{{ $user->name }}</span>
                        <span class="block text-xs leading-tight text-slate-500">{{ $user->namaPeran() }}</span>
                    </span>
                    <x-ui.icon name="chevron-down" variant="m" class="hidden h-4 w-4 text-slate-400 sm:block" />
                </button>
            </x-slot:trigger>

            <div class="border-b border-slate-100 px-4 py-3">
                <p class="truncate text-sm font-medium text-slate-900">{{ $user->name }}</p>
                <p class="truncate text-xs text-slate-500">{{ $user->email }}</p>
                <x-ui.badge class="mt-2">{{ $user->namaPeran() }}</x-ui.badge>
            </div>
            <div class="py-1">
                <x-ui.dropdown-item :href="route('profile.edit')" icon="user-circle">Profil saya</x-ui.dropdown-item>
                <x-ui.dropdown-item :href="route('notifications.index')" icon="bell">Notifikasi</x-ui.dropdown-item>
                <x-ui.dropdown-item :href="route('exports.index')" icon="document-arrow-down">Ekspor saya</x-ui.dropdown-item>
                @can('settings.store.view')
                    <x-ui.dropdown-item :href="route('settings.edit')" icon="cog-6-tooth">Pengaturan toko</x-ui.dropdown-item>
                @endcan
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-ui.dropdown-item type="submit" icon="arrow-right-start-on-rectangle">Keluar</x-ui.dropdown-item>
                </form>
            </div>
        </x-ui.dropdown>
    </div>
</header>
