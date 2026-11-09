{{--
    Matriks hak akses satu peran: baris = fitur (per modul), kolom = aksi, centang = diizinkan.
    $granted / $grantable = [nama izin => index] untuk pencarian cepat; $grantable null = boleh semua.
--}}
@php
    $columnCount = count($actions) + 1;
@endphp

<x-app-layout>
    <x-slot name="header">Hak Akses</x-slot>
    <x-slot name="description">Centang aksi yang boleh dilakukan peran ini. Perubahan berlaku pada permintaan berikutnya setiap pengguna dengan peran tersebut.</x-slot>
    @can('access.roles.view')
        <x-slot name="actions">
            <x-ui.button :href="route('roles.index')" variant="secondary" icon="user-group">Kelola peran</x-ui.button>
        </x-slot>
    @endcan

    {{-- Pilih peran --}}
    <nav class="mb-5 flex flex-wrap gap-2" aria-label="Pilih peran">
        @foreach ($roles as $r)
            <a href="{{ route('permissions.index', ['peran' => $r->getKey()]) }}"
               @if ($r->is($role)) aria-current="page" @endif
               @class([
                   'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                   'border-primary-600 bg-primary-600 text-white' => $r->is($role),
                   'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900' => ! $r->is($role),
               ])>
                {{ $r->name }}
            </a>
        @endforeach
    </nav>

    @if ($isSuperAdminRole)
        <x-ui.alert type="info" title="Super Admin selalu memegang semua hak akses" class="mb-5">
            Peran sistem ini tidak dapat diubah. Matriks di bawah hanya untuk dilihat.
        </x-ui.alert>
    @elseif ($readOnly)
        <x-ui.alert type="info" class="mb-5">Anda hanya dapat melihat hak akses. Mengubahnya membutuhkan izin "Matriks hak akses · Ubah".</x-ui.alert>
    @elseif ($grantable !== null)
        <x-ui.alert type="warning" class="mb-5">Anda hanya dapat memberikan hak akses yang Anda miliki sendiri. Kotak yang tidak bisa dicentang berarti Anda tidak memegang izin tersebut.</x-ui.alert>
    @endif

    <form method="POST" action="{{ route('permissions.update', $role) }}"
          x-data="{
              berubah: 0,
              hitung() {
                  this.berubah = [...this.$root.querySelectorAll('input[data-izin]')].filter(el => el.checked !== el.defaultChecked).length;
              },
              setel(modul, nilai) {
                  this.$root.querySelectorAll(`input[data-modul='${modul}']:not(:disabled)`).forEach(el => el.checked = nilai);
                  this.hitung();
              },
              batal() {
                  this.$root.querySelectorAll('input[data-izin]').forEach(el => el.checked = el.defaultChecked);
                  this.hitung();
              },
          }"
          x-on:change="hitung()">
        @csrf
        @method('PUT')

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-card">
            <div class="max-h-[70vh] overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 z-20 bg-slate-50 shadow-[inset_0_-1px_0_theme(colors.slate.200)]">
                        <tr>
                            <th scope="col" class="sticky left-0 z-10 min-w-[14rem] bg-slate-50 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Fitur</th>
                            @foreach ($actions as $action => $label)
                                <th scope="col" class="px-2 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    @foreach ($modules as $moduleKey => $module)
                        @php
                            $moduleCount = collect($module['features'])->flatMap(fn ($f) => $f['permissions'])->filter(fn ($p) => isset($granted[$p]))->count();
                        @endphp
                        <tbody class="border-t border-slate-200">
                            <tr class="bg-slate-50/60">
                                <th scope="rowgroup" colspan="{{ $columnCount }}" class="sticky left-0 px-4 py-2.5 text-left">
                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                        <span class="text-sm font-semibold text-slate-900">{{ $module['label'] }}</span>
                                        <span class="text-xs font-normal text-slate-500">{{ $isSuperAdminRole ? 'semua' : $moduleCount }} diizinkan</span>
                                        @unless ($readOnly)
                                            <span class="flex gap-3 text-xs font-medium">
                                                <button type="button" x-on:click="setel(@js($moduleKey), true)" class="text-primary-600 hover:underline">Pilih semua</button>
                                                <button type="button" x-on:click="setel(@js($moduleKey), false)" class="text-slate-500 hover:underline">Kosongkan</button>
                                            </span>
                                        @endunless
                                    </div>
                                </th>
                            </tr>

                            @foreach ($module['features'] as $featureKey => $feature)
                                <tr class="border-t border-slate-100 hover:bg-slate-50/60">
                                    <th scope="row" class="sticky left-0 bg-white px-4 py-2.5 text-left font-normal text-slate-700">
                                        <span class="flex items-center gap-2">
                                            {{ $feature['label'] }}
                                            @if ($feature['planned'])
                                                <span class="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">Segera Hadir</span>
                                            @endif
                                        </span>
                                    </th>
                                    @foreach ($actions as $action => $actionLabel)
                                        <td class="px-2 py-2.5 text-center">
                                            @isset($feature['permissions'][$action])
                                                @php
                                                    $permission = $feature['permissions'][$action];
                                                    $checked = $isSuperAdminRole || isset($granted[$permission]);
                                                    $disabled = $readOnly || (! $checked && $grantable !== null && ! isset($grantable[$permission]));
                                                @endphp
                                                <input type="checkbox" name="permissions[]" value="{{ $permission }}"
                                                       data-izin data-modul="{{ $moduleKey }}"
                                                       @checked($checked) @disabled($disabled)
                                                       aria-label="{{ $module['label'] }} · {{ $feature['label'] }} · {{ $actionLabel }}"
                                                       title="{{ $permission }}"
                                                       class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500 disabled:cursor-not-allowed disabled:opacity-40">
                                            @else
                                                <span class="text-slate-200" aria-hidden="true">–</span>
                                            @endisset
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    @endforeach
                </table>
            </div>
        </div>

        @unless ($readOnly)
            {{-- Bilah simpan menempel di bawah layar begitu ada perubahan. --}}
            <div x-show="berubah > 0" x-cloak x-transition.opacity
                 class="sticky bottom-4 z-30 mt-4 flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-float sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-slate-700"><span class="font-semibold" x-text="berubah"></span> perubahan belum disimpan untuk peran <span class="font-semibold">{{ $role->name }}</span>.</p>
                <div class="flex gap-2">
                    <x-ui.button variant="secondary" x-on:click="batal()">Batalkan perubahan</x-ui.button>
                    <x-ui.button type="submit" icon="check">Simpan hak akses</x-ui.button>
                </div>
            </div>
        @endunless
    </form>
</x-app-layout>
