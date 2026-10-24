<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-mode="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>API settings — {{ config('app.name') }}</title>
    @include('swagger::partials.favicon')
    @include('swagger::g4t.styles')
    @include('swagger::dashboard.styles')
    <script>
        try { if (localStorage.getItem('g4t-ui:mode') === 'dark') { document.documentElement.dataset.mode = 'dark'; } } catch (e) { }
    </script>
</head>

<body>
    <div class="d-app">
        <header class="g4t-header">
            <div class="g4t-brand">
                @include('swagger::partials.logo', ['size' => 28])
                <div class="g4t-brand-text">
                    <div class="g4t-brand-name">{{ config('app.name') }}</div>
                    <div class="g4t-brand-sub">API settings · g4t/swagger</div>
                </div>
            </div>
            <div class="g4t-spacer"></div>
            <a class="g4t-btn" href="{{ $urls['docs'] }}" target="_blank" rel="noopener">Open documentation</a>
            <form method="POST" action="{{ $urls['logout'] }}" style="margin:0">
                @csrf
                <button type="submit" class="g4t-btn" title="Signed in as {{ $user }}">Sign out</button>
            </form>
        </header>

        <div class="d-layout">
            <nav class="d-nav" id="d-nav" aria-label="Settings sections"></nav>
            <main class="d-main">
                <div class="d-content" id="d-content">
                    <div class="g4t-loading"><span class="g4t-spinner" aria-hidden="true"></span>Loading settings…</div>
                </div>
            </main>
        </div>

        <div class="d-savebar" id="d-savebar" hidden></div>
        <div class="g4t-toast-wrap" aria-live="polite"><div class="g4t-toast" id="d-toast" hidden></div></div>
    </div>

    <script>
        window.G4T_DASHBOARD = {!! json_encode(['urls' => $urls, 'user' => $user], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    </script>
    @include('swagger::dashboard.script')
</body>

</html>
