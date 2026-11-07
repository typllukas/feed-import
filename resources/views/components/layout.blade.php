<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<header>
    <span class="header-start">
        <a href="{{ route('partners.index') }}"><strong>{{ config('app.name') }}</strong></a>
        @auth
            <x-tech-stack-logos />
        @endauth
    </span>
    @auth
        <form method="post" action="{{ route('logout') }}">
            @csrf
            <span class="muted">{{ auth()->user()->email }}</span>
            <button type="submit">Log out</button>
        </form>
    @else
        <x-tech-stack-logos />
    @endauth
</header>
<main>
    {{ $slot }}
</main>
</body>
</html>
