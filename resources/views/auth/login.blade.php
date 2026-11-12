<x-layout title="Log in">
    <form class="form-panel login" method="post" action="{{ route('login.store') }}">
        @csrf
        <h1>Log in</h1>
        @env('local')
            <p class="hint">Demo account, already filled in. Just click Log in.</p>
        @endenv
        <label>E-mail <input type="email" name="email" value="{{ old('email', app()->isLocal() ? 'demo@feed-import.test' : '') }}" required autofocus></label>
        <label>Password <input type="password" name="password" value="{{ app()->isLocal() ? 'feed-import-demo' : '' }}" required></label>
        @error('email')
            <p class="error">{{ $message }}</p>
        @enderror
        @error('password')
            <p class="error">{{ $message }}</p>
        @enderror
        <button type="submit">Log in</button>
    </form>
</x-layout>
