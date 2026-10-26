<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-mode="{{ $g4t['mode'] === 'dark' ? 'dark' : 'light' }}" data-density="{{ $g4t['density'] }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — API reference</title>
    @include('swagger::partials.favicon')
    @include('swagger::g4t.styles')
</head>

<body>
    <div class="g4t-app">
        <header class="g4t-header">
            <button type="button" class="g4t-icon-btn g4t-drawer-btn" data-action="toggle-drawer" aria-label="Toggle operations list" aria-expanded="false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <div class="g4t-brand">
                @if (! empty($partner['brand']['logo']))
                <img class="g4t-brand-logo" src="{{ $partner['brand']['logo'] }}" alt="" height="28" referrerpolicy="no-referrer">
                @else
                @include('swagger::partials.logo', ['size' => 28])
                @endif
                <div class="g4t-brand-text">
                    <div class="g4t-brand-name">{{ config('app.name') }}</div>
                    <div class="g4t-brand-sub">API reference · g4t/swagger</div>
                </div>
            </div>
            <div class="g4t-divider" aria-hidden="true"></div>
            <label class="g4t-select-wrap">
                <span class="g4t-sr">API definition</span>
                <select class="g4t-select g4t-mono" id="g4t-version"></select>
                <svg class="g4t-select-chev" aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"></path></svg>
            </label>
            <div class="g4t-spacer"></div>
            <button type="button" class="g4t-palette-btn" data-action="open-palette" aria-label="Search everything (Ctrl+K)" title="Search endpoints, fields and actions">
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                <span class="g4t-palette-btn-text">Search</span><kbd class="g4t-palette-kbd">⌘K</kbd>
            </button>
            <div class="g4t-pop" data-g4t-pop="envs">
                <button type="button" class="g4t-env-btn" data-action="toggle-env-menu" id="g4t-env-btn" aria-haspopup="menu" aria-expanded="false" title="Switch environment"></button>
                <div class="g4t-menu g4t-env-menu" role="menu" aria-label="Environments" id="g4t-env-menu" hidden></div>
            </div>
            <div class="g4t-pop" data-g4t-pop="download">
                <button type="button" class="g4t-icon-btn" data-action="toggle-download" aria-label="Download the OpenAPI document" aria-haspopup="menu" aria-expanded="false" id="g4t-download-btn">
                    <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"></path></svg>
                </button>
                <div class="g4t-menu g4t-download-menu" role="menu" id="g4t-download" hidden></div>
            </div>
            <div class="g4t-pop" data-g4t-pop="auth" id="g4t-auth"></div>
            <div class="g4t-pop" data-g4t-pop="appear">
                <button type="button" class="g4t-icon-btn" data-action="toggle-appear" aria-label="Appearance" aria-haspopup="dialog" aria-expanded="false" id="g4t-appear-btn">
                    <svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"></circle><path d="M12 3a9 9 0 0 0 0 18z" fill="currentColor"></path></svg>
                </button>
                <div class="g4t-menu g4t-appear-menu" role="dialog" aria-label="Appearance" id="g4t-appear" hidden></div>
            </div>
        </header>

        <div class="g4t-body">
            <div class="g4t-scrim" data-action="close-drawer" hidden></div>
            <aside class="g4t-aside" aria-label="Operations" id="g4t-aside">
                <div class="g4t-filter">
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path></svg>
                    <input type="search" id="g4t-filter" placeholder="Filter operations" aria-label="Filter operations" autocomplete="off">
                    <kbd aria-hidden="true">/</kbd>
                </div>
                <nav id="g4t-nav"></nav>
            </aside>

            <main class="g4t-main">
                <div class="g4t-content">
                    <div id="g4t-info" class="g4t-info"></div>
                    <div id="g4t-ops"></div>
                    <footer class="g4t-footer">
                        @if (empty($partner))
                        <span>Generated by g4t/swagger · also in
                            <a href="?ui=swagger">Swagger UI</a> ·
                            <a href="?ui=scalar">Scalar</a></span>
                        @else
                        <span>Generated by g4t/swagger · shared with {{ $partner['name'] }}</span>
                        @endif
                        <span><kbd>⌘K</kbd> search · <kbd>/</kbd> filter · <kbd>Esc</kbd> close</span>
                    </footer>
                </div>
            </main>
        </div>

        <div id="g4t-login"></div>
        <div id="g4t-envs"></div>
        <div id="g4t-overlay"></div>
        <div class="g4t-toast-wrap" aria-live="polite"><div class="g4t-toast" id="g4t-toast" hidden></div></div>
    </div>

    <script>
        window.G4T_SETTINGS = {!! json_encode([
            'versions' => $versions,
            'autoLogin' => $autoLogin,
            'autoLogins' => $autoLogins ?? [],
            'themes' => $themes,
            'defaultTheme' => $defaultTheme,
            'mode' => $g4t['mode'],
            'density' => $g4t['density'],
            'gettingStarted' => $g4t['getting_started'],
            'environments' => $environments ?? [],
            'jsonUrl' => url(config('swagger.url') . '/json') . (empty($partner) ? '' : '?key=' . urlencode($partner['key'])),
            'partner' => empty($partner) ? null : ['name' => $partner['name'], 'sections' => $partner['sections'], 'endpoints' => $partner['endpoints'] ?? 0, 'expires' => $partner['expires'], 'brand' => $partner['brand'] ?? null],
        ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!};
    </script>
    @include('swagger::g4t.script')
</body>

</html>
