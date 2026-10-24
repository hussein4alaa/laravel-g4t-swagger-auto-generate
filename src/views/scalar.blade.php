<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name') }} - G4T Swagger</title>
    @include('swagger::partials.favicon')
</head>

<body>
    <div id="g4t-scalar"></div>

    @include('swagger::partials.auto-login')

    {{-- Built in PHP: Blade treats "@{{" as an escaped echo. --}}
    <script src="{{ 'https://cdn.jsdelivr.net/npm/@scalar/api-reference@'.$scalar['version'].'/dist/browser/standalone.js' }}"></script>
    <script>
        (function(versions, settings) {
            var configuration = {
                sources: versions.versions.map(function(version) {
                    return {
                        title: version.name,
                        slug: version.name,
                        url: version.url,
                        default: version.name === versions.default
                    };
                }),
                theme: settings.theme,
                layout: settings.layout,
                hideModels: settings.hideModels,
                defaultOpenAllTags: settings.defaultOpenAllTags,
                withDefaultFonts: true,
                // Hide Scalar's hosted-service entry points (cloud toolbar, AI agent, MCP generator).
                showDeveloperTools: 'never',
                agent: {
                    disabled: true
                },
                mcp: {
                    disabled: true
                }
            };

            if (settings.darkMode !== null) {
                configuration.darkMode = settings.darkMode;
                configuration.forceDarkModeState = settings.darkMode ? 'dark' : 'light';
                configuration.hideDarkModeToggle = true;
            }

            if (settings.locale || settings.direction !== 'auto') {
                configuration.localization = {
                    locale: settings.locale || undefined,
                    direction: settings.direction
                };
            }

            if (window.G4TAuth) {
                var token = window.G4TAuth.token();
                if (token) {
                    var securitySchemes = {};
                    securitySchemes[window.G4TAuth.scheme] = {
                        token: token,
                        value: token
                    };
                    configuration.authentication = {
                        preferredSecurityScheme: window.G4TAuth.scheme,
                        securitySchemes: securitySchemes
                    };
                }
                // Scalar reads authentication once, so a new token needs a fresh page.
                window.G4TAuth.onChange(function() {
                    window.location.reload();
                });
            }

            Scalar.createApiReference('#g4t-scalar', configuration);
        })({!! json_encode($versions, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}, {!! json_encode($scalar, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});
    </script>
</body>

</html>
