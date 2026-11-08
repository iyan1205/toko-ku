{{-- Halaman pengganti untuk menu berstatus coming_soon (ComingSoonController). --}}
<x-app-layout>
    <x-slot name="header">{{ $item['label'] }}</x-slot>
    @if (! empty($item['description']))
        <x-slot name="description">{{ $item['description'] }}</x-slot>
    @endif
    <x-slot name="actions">
        <x-ui.badge color="info" dot>Segera Hadir</x-ui.badge>
    </x-slot>

    <x-ui.card :padding="false">
        <x-ui.empty-state icon="rocket-launch" title="Fitur ini sedang disiapkan"
                          :description="($parent ? $parent['label'].' · ' : '').$item['label'].' akan tersedia di pembaruan berikutnya. Menu lain tetap bisa dipakai seperti biasa.'">
            <x-ui.button :href="route('dashboard')" variant="secondary" icon="arrow-left">Kembali ke Dashboard</x-ui.button>
        </x-ui.empty-state>
    </x-ui.card>
</x-app-layout>
