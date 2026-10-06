<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Masuk · {{ config('app.name') }}</title>
    <style>[x-cloak]{display:none !important}</style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-stone-100 text-stone-800 antialiased">
<div class="flex min-h-screen items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <div class="text-4xl">🏪</div>
            <p class="mt-2 text-xl font-semibold text-stone-900">{{ config('app.name') }}</p>
        </div>
        <div class="rounded-lg border border-stone-200 bg-white p-6 shadow-sm">
            {{ $slot }}
        </div>
    </div>
</div>
@include('partials.toast')
</body>
</html>
