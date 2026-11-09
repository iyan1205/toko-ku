{{-- Tambah / ubah pengguna. $peran = peran yang boleh diberikan oleh pengguna yang sedang login. --}}
@php
    $edit = $user->exists;
    $peranSaatIni = old('role', $edit ? $user->roles->pluck('name')->first() : \App\Enums\RoleName::Kasir->value);
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah pengguna' : 'Tambah pengguna' }}</x-slot>
    <x-slot name="description">{{ $edit ? $user->email : 'Akun login baru untuk pegawai. Kata sandi bisa diganti sendiri oleh pegawai lewat Profil Saya.' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('users.update', $user) : route('users.store') }}" class="grid gap-8">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <x-ui.form-section title="Data akun" description="Nama tampil di struk dan riwayat transaksi. Email dipakai untuk login.">
            <x-ui.input name="name" label="Nama" :value="$user->name" maxlength="100" required />
            <div class="grid gap-5 sm:grid-cols-2">
                <x-ui.input name="email" label="Email" type="email" :value="$user->email" autocomplete="off" required />
                <x-ui.input name="phone" label="Nomor HP" type="tel" :value="$user->phone" maxlength="20" />
            </div>
        </x-ui.form-section>

        @unless ($edit)
            <x-ui.form-section title="Kata sandi" description="Minimal 8 karakter, mengandung huruf dan angka.">
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="password" label="Kata sandi" type="password" autocomplete="new-password" required />
                    <x-ui.input name="password_confirmation" label="Ulangi kata sandi" type="password" autocomplete="new-password" required />
                </div>
            </x-ui.form-section>
        @endunless

        <x-ui.form-section title="Peran & status" description="Peran menentukan menu dan aksi yang boleh dipakai. Atur rinciannya di Hak Akses.">
            <fieldset>
                <legend class="text-sm font-medium text-slate-700">Peran</legend>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach ($peran as $p)
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-slate-200 p-3 transition-colors hover:bg-slate-50 has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50/60">
                            <input type="radio" name="role" value="{{ $p->name }}" @checked($peranSaatIni === $p->name)
                                   class="mt-0.5 border-slate-300 text-primary-600 focus:ring-primary-500">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-slate-900">{{ $p->name }}</span>
                                @if ($p->description)
                                    <span class="block text-xs text-slate-500">{{ $p->description }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('role')
                    <p class="mt-1.5 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </fieldset>

            <x-ui.checkbox name="is_active" label="Akun aktif dan boleh login" :checked="$user->is_active ?? true" unchecked-value="0" />

            <x-slot:footer>
                <x-ui.button :href="route('users.index')" variant="secondary">Batal</x-ui.button>
                <x-ui.button type="submit">{{ $edit ? 'Simpan perubahan' : 'Simpan pengguna' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.form-section>
    </form>
</x-app-layout>
