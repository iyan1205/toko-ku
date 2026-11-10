<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Pusat notifikasi milik pengguna login: lonceng di topbar (JSON), halaman semua notifikasi,
 * tandai dibaca, dan preferensi kanal (Profil Saya). Hanya notifikasi milik sendiri yang bisa dibuka.
 */
class NotificationCenterController extends Controller
{
    /** Jumlah notifikasi di dropdown lonceng. */
    private const FEED_LIMIT = 8;

    public function index(Request $request): View
    {
        $filter = $request->validate(['status' => ['nullable', 'in:belum-dibaca']]);

        return view('notifications.index', [
            'notifications' => $request->user()->notifications()
                ->when(($filter['status'] ?? null) === 'belum-dibaca', fn ($q) => $q->whereNull('read_at'))
                ->paginate(20)->withQueryString(),
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** Isi dropdown lonceng: jumlah belum dibaca + notifikasi terbaru. */
    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->limit(self::FEED_LIMIT)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? 'Notifikasi',
                'body' => $n->data['body'] ?? '',
                'icon' => $n->data['icon'] ?? 'bell',
                'url' => route('notifications.open', $n->id),
                'time' => $n->created_at?->locale('id')->diffForHumans(),
                'read' => $n->read_at !== null,
            ]),
        ]);
    }

    /** Buka notifikasi: tandai dibaca lalu pindah ke halaman tujuannya. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $n = $this->find($request, $notification);
        $n->markAsRead();

        $url = (string) ($n->data['url'] ?? '');

        return $this->isInternal($url) ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    public function read(Request $request, string $notification): RedirectResponse|JsonResponse
    {
        $this->find($request, $notification)->markAsRead();

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Semua notifikasi ditandai sudah dibaca.');
    }

    public function updatePreferences(Request $request, NotificationPreferenceService $preferences): RedirectResponse
    {
        $types = array_keys($preferences->types());
        $data = $request->validate([
            'preferences' => ['nullable', 'array'],
            'preferences.*' => ['array'],
            'preferences.*.database' => ['nullable', 'boolean'],
            'preferences.*.mail' => ['nullable', 'boolean'],
        ]);

        $preferences->save($request->user(), array_intersect_key($data['preferences'] ?? [], array_flip($types)));

        return redirect()->route('profile.edit')->with('success', 'Preferensi notifikasi disimpan.');
    }

    private function find(Request $request, string $id): DatabaseNotification
    {
        return $request->user()->notifications()->whereKey($id)->firstOrFail();
    }

    /** Hanya pindah ke URL aplikasi ini (mencegah open redirect). */
    private function isInternal(string $url): bool
    {
        return $url !== '' && (Str::startsWith($url, url('/').'/') || $url === url('/') || (Str::startsWith($url, '/') && ! Str::startsWith($url, '//')));
    }
}
