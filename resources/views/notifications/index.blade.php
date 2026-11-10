{{-- Semua notifikasi milik pengguna login. Klik notifikasi = tandai dibaca lalu buka halamannya. --}}
@use('App\Support\Format')

<x-app-layout>
    <x-slot name="header">Notifikasi</x-slot>
    <x-slot name="description">Pemberitahuan untuk Anda: persetujuan dokumen, hasil persetujuan, dan ekspor data. Atur jenisnya di Profil Saya.</x-slot>
    <x-slot name="actions">
        <x-ui.button variant="secondary" icon="cog-6-tooth" :href="route('profile.edit')">Preferensi</x-ui.button>
        @if ($unread > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <x-ui.button type="submit" icon="check">Tandai semua dibaca</x-ui.button>
            </form>
        @endif
    </x-slot>

    <nav class="mb-4 flex gap-1 border-b border-slate-200" aria-label="Saring notifikasi">
        @foreach (['' => 'Semua', 'belum-dibaca' => 'Belum dibaca ('.$unread.')'] as $kunci => $label)
            <a href="{{ route('notifications.index', array_filter(['status' => $kunci])) }}" @class([
                '-mb-px border-b-2 px-4 py-2 text-sm font-medium',
                'border-primary-600 text-primary-700' => request('status', '') === $kunci,
                'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' => request('status', '') !== $kunci,
            ])>{{ $label }}</a>
        @endforeach
    </nav>

    <x-ui.card :padding="false">
        <ul class="divide-y divide-slate-100">
            @forelse ($notifications as $n)
                <li @class(['flex items-start gap-3 px-5 py-4', 'bg-primary-50/40' => $n->read_at === null])>
                    <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full bg-slate-100 text-slate-500">
                        <x-ui.icon :name="$n->data['icon'] ?? 'bell'" class="h-4 w-4" />
                    </span>
                    <a href="{{ route('notifications.open', $n->id) }}" class="min-w-0 flex-1">
                        <p @class(['text-sm text-slate-900', 'font-semibold' => $n->read_at === null, 'font-medium' => $n->read_at !== null])>{{ $n->data['title'] ?? 'Notifikasi' }}</p>
                        <p class="mt-0.5 text-sm text-slate-600">{{ $n->data['body'] ?? '' }}</p>
                        <p class="mt-1 text-xs text-slate-400">{{ Format::tanggal($n->created_at, true) }} · {{ $n->created_at->locale('id')->diffForHumans() }}</p>
                    </a>
                    @if ($n->read_at === null)
                        <form method="POST" action="{{ route('notifications.read', $n->id) }}">
                            @csrf
                            <x-ui.button type="submit" variant="ghost" size="sm" icon="check" aria-label="Tandai dibaca" title="Tandai dibaca" />
                        </form>
                    @endif
                </li>
            @empty
                <li><x-ui.empty-state icon="bell" title="Belum ada notifikasi" description="Notifikasi muncul saat ada dokumen yang perlu Anda setujui, hasil persetujuan, atau file ekspor yang siap." /></li>
            @endforelse
        </ul>
        @if ($notifications->hasPages())
            <div class="border-t border-slate-200 px-5 py-3">{{ $notifications->links() }}</div>
        @endif
    </x-ui.card>
</x-app-layout>
