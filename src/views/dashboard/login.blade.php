<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-mode="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Sign in — {{ config('app.name') }} API settings</title>
    @include('swagger::partials.favicon')
    @include('swagger::g4t.styles')
    @include('swagger::dashboard.styles')
    <script>
        try { if (localStorage.getItem('g4t-ui:mode') === 'dark') { document.documentElement.dataset.mode = 'dark'; } } catch (e) { }
    </script>
</head>

<body>
    <main class="d-login">
        <form class="d-login-card" method="POST" action="{{ $action }}" novalidate>
            @csrf
            <input type="hidden" name="page" value="{{ $page ?? '' }}">
            <div class="d-login-brand">
                @include('swagger::partials.logo', ['size' => 40])
                <div>
                    <div class="d-login-title">API settings</div>
                    <div class="g4t-muted" style="font-size:13px">{{ config('app.name') }} · g4t/swagger</div>
                </div>
            </div>
            <p class="g4t-muted" style="margin:0;font-size:13.5px">Sign in with the dashboard credentials from your <code class="g4t-mono">.env</code>.</p>
            @isset($error)
                <p class="g4t-login-error" role="alert" style="margin:0">{{ $error }}</p>
            @endisset
            <label class="d-field">Username
                <input name="username" type="text" autocomplete="username" required autofocus value="{{ $username ?? '' }}">
            </label>
            <label class="d-field">Password
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="g4t-btn g4t-btn-primary" style="height:38px">Sign in</button>
        </form>
    </main>
</body>

</html>
