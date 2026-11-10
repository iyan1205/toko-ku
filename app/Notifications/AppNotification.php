<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Dasar semua notifikasi aplikasi: lonceng di topbar (kanal database) dan email.
 *
 *   - Kanal mengikuti preferensi pengguna (NotificationPreferenceService) untuk type().
 *   - Kanal database dikirim langsung (koneksi sync) supaya lonceng langsung terisi;
 *     email lewat queue (QUEUE_CONNECTION), dikirim oleh queue worker (docs/DEPLOY.md).
 *
 * Isi notifikasi di lonceng = toArray(): title, body, url, icon.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    /** Kunci di config/notification-types.php. */
    abstract public static function type(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    abstract public function url(): ?string;

    public function icon(): string
    {
        return 'bell';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof User
            ? app(NotificationPreferenceService::class)->channels($notifiable, static::type())
            : ['database'];
    }

    /** @return array<string, string> */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    /** @return array{type: string, title: string, body: string, url: ?string, icon: string} */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => static::type(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'icon' => $this->icon(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting('Halo, '.($notifiable->name ?? '').'!')
            ->line($this->body());

        if ($this->url()) {
            $mail->action('Buka di '.config('app.name'), $this->url());
        }

        return $mail
            ->line('Atur email notifikasi di Profil Saya → Notifikasi.')
            ->salutation('Salam, '.config('app.name'));
    }
}
