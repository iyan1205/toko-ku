@php
    $edit = $user->exists;
    $peranSaatIni = old('role', $edit ? $user->roles->pluck('name')->first() : \App\Enums\RoleName::Kasir->value);
    $aktif = old('is_active', $user->is_active ?? true);
    $inputClass = 'mt-1 block w-full rounded-md border-stone-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600';
@endphp

<x-app-layout>
    <x-slot name="header">{{ $edit ? 'Ubah pengguna' : 'Tambah pengguna' }}</x-slot>

    <form method="POST" action="{{ $edit ? route('users.update', $user) : route('users.store') }}"
          class="max-w-2xl space-y-5 rounded-lg border border-stone-200 bg-white p-6">
        @csrf
        @if ($edit)
            @method('PUT')
        @endif

        <div>
            <label for="name" class="block text-sm font-medium text-stone-700">Nama</label>
            <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}" required maxlength="100" class="{{ $inputClass }}">
            @error('name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="email" class="block text-sm font-medium text-stone-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="off" class="{{ $inputClass }}">
                @error('email') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium text-stone-700">Nomor HP <span class="font-normal text-stone-400">(opsional)</span></label>
                <input id="phone" name="phone" type="tel" value="{{ old('phone', $user->phone) }}" maxlength="20" class="{{ $inputClass }}">
                @error('phone') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label for="password" class="block text-sm font-medium text-stone-700">
                    Kata sandi
                    @if ($edit) <span class="font-normal text-stone-400">(kosongkan bila tidak diganti)</span> @endif
                </label>
                <input id="password" name="password" type="password" @required(! $edit) autocomplete="new-password" class="{{ $inputClass }}">
                <p class="mt-1 text-xs text-stone-500">Minimal 8 karakter, mengandung huruf dan angka.</p>
                @error('password') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-stone-700">Ulangi kata sandi</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" class="{{ $inputClass }}">
            </div>
        </div>

        <fieldset>
            <legend class="text-sm font-medium text-stone-700">Peran</legend>
            <div class="mt-2 space-y-2">
                @foreach ($peran as $p)
                    <label class="flex cursor-pointer items-start gap-3 rounded-md border border-stone-200 p-3 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                        <input type="radio" name="role" value="{{ $p->value }}" @checked($peranSaatIni === $p->value)
                               class="mt-1 border-stone-300 text-teal-700 focus:ring-teal-600">
                        <span>
                            <span class="block text-sm font-medium text-stone-900">{{ $p->value }}</span>
                            <span class="block text-sm text-stone-600">{{ $p->deskripsi() }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('role') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
        </fieldset>

        <label for="is_active" class="flex items-center gap-2 text-sm text-stone-700">
            <input id="is_active" name="is_active" type="checkbox" value="1" @checked($aktif)
                   class="rounded border-stone-300 text-teal-700 focus:ring-teal-600">
            Akun aktif dan boleh login
        </label>

        <div class="flex justify-end gap-2 border-t border-stone-100 pt-5">
            <a href="{{ route('users.index') }}" class="rounded-md border border-stone-300 px-4 py-2 text-sm text-stone-700 hover:bg-stone-50">Batal</a>
            <button type="submit"
                    class="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                {{ $edit ? 'Simpan perubahan' : 'Simpan pengguna' }}
            </button>
        </div>
    </form>
</x-app-layout>
