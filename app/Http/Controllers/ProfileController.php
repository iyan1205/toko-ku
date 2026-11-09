<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Profil saya: setiap pengguna login mengubah nama, email, nomor HP, dan kata sandinya sendiri.
 * Peran dan status akun tidak bisa diubah di sini (lewat menu Pengguna).
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated())->save();

        return redirect()->route('profile.edit')->with('success', 'Profil Anda diperbarui.');
    }

    /** Ganti kata sandi sendiri; sesi login di perangkat lain diakhiri. */
    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            $user->forceFill(['password' => $request->validated('password')])->save();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->where('id', '!=', $request->session()->getId())
                    ->delete();
            }

            activity('pengguna')->performedOn($user)->event('password_changed')
                ->log("{$user->name} mengganti kata sandinya");
        });

        return redirect()->route('profile.edit')->with('success', 'Kata sandi diganti. Sesi login di perangkat lain telah diakhiri.');
    }
}
