{{-- Profil saya: data akun sendiri dan ganti kata sandi. --}}
<x-app-layout>
    <x-slot name="header">Profil Saya</x-slot>
    <x-slot name="description">{{ $user->namaPeran() }} · login terakhir {{ \App\Support\Format::tanggal($user->last_login_at, true) }}</x-slot>

    <div class="grid gap-8">
        <form method="POST" action="{{ route('profile.update') }}">
            @csrf
            @method('PUT')
            <x-ui.form-section title="Data akun" description="Nama tampil di struk dan riwayat transaksi. Email dipakai untuk login.">
                <x-ui.input name="name" label="Nama" :value="$user->name" maxlength="100" required />
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="email" label="Email" type="email" :value="$user->email" autocomplete="email" required />
                    <x-ui.input name="phone" label="Nomor HP" type="tel" :value="$user->phone" maxlength="20" />
                </div>
                <x-slot:footer>
                    <x-ui.button type="submit">Simpan profil</x-ui.button>
                </x-slot:footer>
            </x-ui.form-section>
        </form>

        <form method="POST" action="{{ route('profile.password') }}">
            @csrf
            @method('PUT')
            <x-ui.form-section title="Ganti kata sandi" description="Minimal 8 karakter, mengandung huruf dan angka. Sesi login di perangkat lain akan diakhiri.">
                <x-ui.input name="current_password" label="Kata sandi saat ini" type="password" autocomplete="current-password" required />
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.input name="password" label="Kata sandi baru" type="password" autocomplete="new-password" required />
                    <x-ui.input name="password_confirmation" label="Ulangi kata sandi baru" type="password" autocomplete="new-password" required />
                </div>
                <x-slot:footer>
                    <x-ui.button type="submit" icon="key">Ganti kata sandi</x-ui.button>
                </x-slot:footer>
            </x-ui.form-section>
        </form>

        <form method="POST" action="{{ route('profile.notifications') }}">
            @csrf
            @method('PUT')
            <x-ui.form-section title="Notifikasi" description="Pilih notifikasi yang muncul di lonceng dan yang dikirim ke email {{ $user->email }}.">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th class="pb-2 pr-4">Jenis</th>
                                <th class="w-28 pb-2 text-center">Di aplikasi</th>
                                <th class="w-28 pb-2 text-center">Email</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($notificationTypes as $kunci => $jenis)
                                <tr>
                                    <td class="py-3 pr-4">
                                        <p class="font-medium text-slate-900">{{ $jenis['label'] }}</p>
                                        <p class="text-xs text-slate-500">{{ $jenis['description'] }}</p>
                                    </td>
                                    @foreach (['database' => 'di aplikasi', 'mail' => 'lewat email'] as $kanal => $teks)
                                        <td class="py-3 text-center">
                                            <input type="hidden" name="preferences[{{ $kunci }}][{{ $kanal }}]" value="0">
                                            <input type="checkbox" name="preferences[{{ $kunci }}][{{ $kanal }}]" value="1" @checked($notificationChoices[$kunci][$kanal] ?? false)
                                                   aria-label="{{ $jenis['label'] }} {{ $teks }}"
                                                   class="rounded border-slate-300 text-primary-600 focus:ring-primary-500">
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <x-slot:footer>
                    <x-ui.button type="submit">Simpan preferensi</x-ui.button>
                </x-slot:footer>
            </x-ui.form-section>
        </form>
    </div>
</x-app-layout>
