<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('judul') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-stone-100 text-stone-800 antialiased">
<div class="flex min-h-screen items-center justify-center px-4">
    <div class="max-w-md text-center">
        <p class="text-6xl font-semibold text-teal-700">@yield('kode')</p>
        <h1 class="mt-3 text-xl font-semibold text-stone-900">@yield('judul')</h1>
        <p class="mt-2 text-sm text-stone-600">@yield('pesan')</p>
        <a href="{{ url('/') }}" class="mt-6 inline-block rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800">Kembali ke beranda</a>
    </div>
</div>
</body>
</html>
