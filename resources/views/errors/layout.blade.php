<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('judul') · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-slate-50 font-sans text-slate-800 antialiased">
<div class="flex min-h-screen items-center justify-center px-4">
    <div class="max-w-md text-center">
        <p class="text-6xl font-semibold tracking-tight text-primary-600">@yield('kode')</p>
        <h1 class="mt-3 text-xl font-semibold text-slate-900">@yield('judul')</h1>
        <p class="mt-2 text-sm text-slate-600">@yield('pesan')</p>
        <a href="{{ url('/') }}" class="mt-6 inline-flex rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2">Kembali ke beranda</a>
    </div>
</div>
</body>
</html>
