<?php

namespace App\Services\Notifications;

use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Kanal notifikasi per pengguna: pilihan di Profil Saya → Notifikasi, atau bawaan
 * config/notification-types.php bila belum pernah diatur.
 */
class NotificationPreferenceService
{
    /** @return array<string, array{label: string, description: string, database: bool, mail: bool}> */
    public function types(): array
    {
        return config('notification-types', []);
    }

    /**
     * Pilihan pengguna untuk semua jenis.
     *
     * @return array<string, array{database: bool, mail: bool}>
     */
    public function for(User $user): array
    {
        $saved = NotificationPreference::query()->where('user_id', $user->getKey())->get()->keyBy('type');

        return collect($this->types())->map(fn (array $type, string $key) => [
            'database' => (bool) ($saved[$key]->database ?? $type['database']),
            'mail' => (bool) ($saved[$key]->mail ?? $type['mail']),
        ])->all();
    }

    /** @return list<string> kanal Laravel untuk notifikasi ini: database dan/atau mail */
    public function channels(User $user, string $type): array
    {
        $pref = $this->for($user)[$type] ?? ['database' => true, 'mail' => false];

        return array_values(array_filter([
            $pref['database'] ? 'database' : null,
            $pref['mail'] && filled($user->email) ? 'mail' : null,
        ]));
    }

    /** @param array<string, array{database?: mixed, mail?: mixed}> $choices */
    public function save(User $user, array $choices): void
    {
        foreach (array_keys($this->types()) as $type) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'type' => $type],
                ['database' => (bool) ($choices[$type]['database'] ?? false), 'mail' => (bool) ($choices[$type]['mail'] ?? false)],
            );
        }
    }
}
