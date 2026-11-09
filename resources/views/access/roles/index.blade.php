{{-- Daftar peran. Hak akses tiap peran diatur di halaman Hak Akses. --}}
@php
    $totalPermissions = count(\App\Support\PermissionCatalog::all());
@endphp

<x-app-layout>
    <x-slot name="header">Peran</x-slot>
    <x-slot name="description">Peran mengelompokkan hak akses. Setiap pengguna memegang satu peran.</x-slot>
    @can('create', \Spatie\Permission\Models\Role::class)
        <x-slot name="actions">
            <x-ui.button :href="route('roles.create')" icon="plus">Tambah peran</x-ui.button>
        </x-slot>
    @endcan

    <x-ui.table :sticky="false">
        <x-slot:head>
            <x-ui.table.th>Peran</x-ui.table.th>
            <x-ui.table.th align="right">Pengguna</x-ui.table.th>
            <x-ui.table.th align="right">Hak akses</x-ui.table.th>
            <x-ui.table.th align="right"><span class="sr-only">Aksi</span></x-ui.table.th>
        </x-slot:head>

        @foreach ($roles as $role)
            @php
                $superAdmin = $service->isSuperAdmin($role);
                $builtIn = $service->isBuiltIn($role);
            @endphp
            <tr>
                <x-ui.table.td>
                    <p class="flex flex-wrap items-center gap-2 font-medium text-slate-900">
                        {{ $role->name }}
                        @if ($superAdmin)
                            <x-ui.badge color="primary">Sistem</x-ui.badge>
                        @elseif ($builtIn)
                            <x-ui.badge>Bawaan</x-ui.badge>
                        @endif
                    </p>
                    @if ($role->description)
                        <p class="mt-0.5 max-w-xl text-xs text-slate-500">{{ $role->description }}</p>
                    @endif
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    @can('viewAny', \App\Models\User::class)
                        <a href="{{ route('users.index', ['peran' => $role->name]) }}" class="text-primary-600 hover:underline">{{ $role->users_count }}</a>
                    @else
                        {{ $role->users_count }}
                    @endcan
                </x-ui.table.td>
                <x-ui.table.td align="right" numeric>
                    {{ $superAdmin ? 'Semua' : $role->permissions_count.' / '.$totalPermissions }}
                </x-ui.table.td>
                <x-ui.table.td align="right">
                    <div class="flex items-center justify-end gap-1">
                        @can('access.permissions.view')
                            <x-ui.button variant="ghost" size="sm" icon="shield-check" :href="route('permissions.index', ['peran' => $role->getKey()])">Hak akses</x-ui.button>
                        @endcan
                        @can('update', $role)
                            <x-ui.button variant="ghost" size="sm" icon="pencil-square" :href="route('roles.edit', $role)" aria-label="Ubah {{ $role->name }}" />
                        @endcan
                        @can('delete', $role)
                            <x-ui.button variant="ghost" size="sm" icon="trash" aria-label="Hapus {{ $role->name }}" class="text-danger-600 hover:text-danger-700"
                                x-data x-on:click="$dispatch('konfirmasi-hapus', { action: {{ \Illuminate\Support\Js::from(route('roles.destroy', $role)) }}, nama: {{ \Illuminate\Support\Js::from('Peran '.$role->name) }}, pesan: 'akan dihapus. Peran yang masih dipakai pengguna tidak dapat dihapus.' })" />
                        @endcan
                    </div>
                </x-ui.table.td>
            </tr>
        @endforeach
    </x-ui.table>
</x-app-layout>
