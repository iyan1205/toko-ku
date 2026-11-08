{{--
    Sidebar layouts.app. Menu dari $menuTree (App\Support\Menu::tree). Di layar kecil menjadi drawer;
    di layar lebar bisa diciutkan ke ikon saja (lihat .layout-sidebar di resources/css/app.css).
--}}
<aside class="layout-sidebar fixed inset-y-0 left-0 z-40 flex -translate-x-full flex-col border-r border-slate-200 bg-white lg:translate-x-0 print:hidden"
       x-bind:class="sidebarOpen && '!translate-x-0 shadow-xl'"
       aria-label="Sidebar">

    {{-- Identitas toko --}}
    <div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 px-4">
        <a href="{{ route('dashboard') }}" class="sidebar-item flex min-w-0 flex-1 items-center gap-3 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500">
            <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary-600 text-white">
                <x-ui.icon name="building-storefront" class="h-5 w-5" />
            </span>
            <span class="sidebar-label min-w-0">
                <span class="block truncate text-sm font-semibold leading-tight text-slate-900">{{ $storeName }}</span>
                <span class="block text-xs leading-tight text-slate-500">Point of Sale</span>
            </span>
        </a>
        <button type="button" x-on:click="sidebarOpen = false"
                class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 lg:hidden"
                aria-label="Tutup menu">
            <x-ui.icon name="x-mark" class="h-5 w-5" />
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Menu utama">
        <div class="grid gap-5">
            @foreach ($menuTree as $group)
                <div>
                    <p class="sidebar-label px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $group['label'] }}</p>
                    <div class="sidebar-divider mx-2 mb-2 hidden border-t border-slate-200" aria-hidden="true"></div>

                    <ul class="grid gap-0.5">
                        @foreach ($group['items'] as $item)
                            @php
                                $comingSoon = $item['status'] === \App\Support\Menu::COMING_SOON;
                                $iconClass = $item['is_active'] ? 'h-5 w-5 text-primary-600' : 'h-5 w-5 text-slate-400 group-hover:text-slate-500';
                            @endphp
                            <li>
                                @isset($item['children'])
                                    {{-- Menu induk: buka/tutup submenu. Saat sidebar ciut, klik melebarkan sidebar dulu. --}}
                                    <div x-data="{ buka: @js($item['is_active']) }">
                                        <button type="button"
                                                x-on:click="if (sedangCiut()) { ciutkan(false); buka = true } else { buka = ! buka }"
                                                x-bind:aria-expanded="buka.toString()"
                                                aria-controls="submenu-{{ $item['key'] }}"
                                                title="{{ $item['label'] }}"
                                                @class([
                                                    'sidebar-item group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                                                    'text-slate-900' => $item['is_active'],
                                                    'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $item['is_active'],
                                                ])>
                                            <x-ui.icon :name="$item['icon'] ?? 'squares-2x2'" :class="$iconClass" />
                                            <span class="sidebar-label flex-1 truncate text-left">{{ $item['label'] }}</span>
                                            @if ($comingSoon)
                                                <span class="sidebar-label rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">Segera Hadir</span>
                                            @endif
                                            <x-ui.icon name="chevron-down" variant="m" class="sidebar-label h-4 w-4 text-slate-400 transition-transform" x-bind:class="buka && 'rotate-180'" />
                                        </button>

                                        <ul id="submenu-{{ $item['key'] }}" x-show="buka" @unless ($item['is_active']) x-cloak @endunless
                                            class="sidebar-label mt-0.5 grid gap-0.5 pl-11">
                                            @foreach ($item['children'] as $child)
                                                <li>
                                                    <a href="{{ $child['url'] }}" @if ($child['is_active']) aria-current="page" @endif
                                                       @class([
                                                           'flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                                                           'bg-primary-50 font-medium text-primary-700' => $child['is_active'],
                                                           'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $child['is_active'],
                                                       ])>
                                                        <span class="min-w-0 flex-1 truncate">{{ $child['label'] }}</span>
                                                        @if ($child['status'] === \App\Support\Menu::COMING_SOON && ! $comingSoon)
                                                            <span class="shrink-0 rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">Segera Hadir</span>
                                                        @endif
                                                    </a>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @else
                                    <a href="{{ $item['url'] }}" @if ($item['is_active']) aria-current="page" @endif title="{{ $item['label'] }}"
                                       @class([
                                           'sidebar-item group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                                           'bg-primary-50 text-primary-700' => $item['is_active'],
                                           'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $item['is_active'],
                                       ])>
                                        <x-ui.icon :name="$item['icon'] ?? 'squares-2x2'" :class="$iconClass" />
                                        <span class="sidebar-label flex-1 truncate">{{ $item['label'] }}</span>
                                        @if ($comingSoon)
                                            <span class="sidebar-label rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">Segera Hadir</span>
                                        @endif
                                    </a>
                                @endisset
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </nav>
</aside>
