{{--
    Ikon Heroicons (blade-ui-kit/blade-heroicons).
    variant: o = outline 24px (bawaan), s = solid 24px, m = mini 20px, c = micro 16px.

    <x-ui.icon name="home" />
    <x-ui.icon name="trash" variant="m" class="h-4 w-4 text-danger-600" />
--}}
@props(['name', 'variant' => 'o'])

{{ svg(
    'heroicon-'.$variant.'-'.$name,
    'shrink-0 '.($attributes->get('class') ?? 'h-5 w-5'),
    $attributes->except('class')->merge(['aria-hidden' => 'true'])->getAttributes(),
) }}
