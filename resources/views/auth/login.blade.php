<x-guest-layout>
    <h1 class="text-lg font-semibold text-stone-900">Masuk ke akun Anda</h1>
    <p class="mt-1 text-sm text-stone-500">Gunakan email dan kata sandi yang diberikan admin.</p>

    <form method="POST" action="{{ route('login') }}" class="mt-5 space-y-4">
        @csrf

        <div>
            <label for="email" class="label-isian">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   @class(['isian mt-1', 'isian-salah' => $errors->has('email')])>
            @error('email')
                <p class="mt-1 text-sm text-brand-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="label-isian">Kata sandi</label>
            <input id="password" name="password" type="password" required autocomplete="current-password"
                   @class(['isian mt-1', 'isian-salah' => $errors->has('password')])>
            @error('password')
                <p class="mt-1 text-sm text-brand-600">{{ $message }}</p>
            @enderror
        </div>

        <label for="remember" class="flex items-center gap-2 text-sm text-stone-600">
            <input id="remember" name="remember" type="checkbox" class="rounded border-stone-300 text-brand-700 focus:ring-brand-600">
            Ingat saya di perangkat ini
        </label>

        <button type="submit" class="tombol-utama w-full py-2.5">
            Masuk
        </button>
    </form>
</x-guest-layout>
