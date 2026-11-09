<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Str;

/**
 * Catat login, logout, dan login gagal ke log aktivitas (kelompok "auth").
 * Didaftarkan otomatis oleh event discovery Laravel (method berawalan "handle").
 * Kata sandi tidak pernah dicatat; untuk login gagal hanya email yang dicoba.
 */
class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        activity('auth')->causedBy($event->user)->performedOn($event->user)->event('login')
            ->withProperties($this->requestInfo())
            ->log('Login');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        activity('auth')->causedBy($event->user)->performedOn($event->user)->event('logout')
            ->withProperties($this->requestInfo())
            ->log('Logout');
    }

    public function handleFailed(Failed $event): void
    {
        activity('auth')->event('login_failed')
            ->withProperties([...$this->requestInfo(), 'email' => Str::limit((string) ($event->credentials['email'] ?? ''), 255, '')])
            ->log('Login gagal');
    }

    /** @return array{ip: ?string, perangkat: string} */
    private function requestInfo(): array
    {
        return [
            'ip' => request()->ip(),
            'perangkat' => Str::limit((string) request()->userAgent(), 150),
        ];
    }
}
