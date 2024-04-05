<!DOCTYPE html>
<html>

<head>
    <title>G4T Swagger Documentation</title>
    <script src="{{ asset('g4t/swagger/js/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('g4t/swagger/js/js-yaml.min.js') }}"></script>
    <script src="{{ asset('g4t/swagger/js/swagger-ui-bundle.js') }}"></script>
    <link rel="stylesheet" type="text/css" href="{{ asset('g4t/swagger/css/swagger-ui.css') }}" />
    <link rel="stylesheet" href="{{ asset('g4t/swagger/css/style.css') }}">
</head>

<body>

    <div class="swagger-nav">
        <div class="swagger-logo-cont">
            <img src="https://leizl.gallerycdn.vsassets.io/extensions/leizl/swagger-generate-ts/0.0.10/1673338730649/Microsoft.VisualStudio.Services.Icons.Default"
                class="swagger-logo">
            <span class="swagger-logo-title">G4T Swagger</span>
        </div>
        <div class="versions-cont">
            <label for="select-version" class="version-label">API Version</label>
            <select name="" id="select-version" class="version-select">
                <option value="">All</option>
                @foreach ($versions as $version)
                    <option value="{{ $version }}" @if (request()->version == $version) selected @endif>
                        {{ $version }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div id="swagger-ui"></div>

    <script>
        const selectVersion = document.getElementById('select-version');
        selectVersion.addEventListener('change', function() {
            const selectedVersion = selectVersion.value;
            const url = '?version=' + encodeURIComponent(
                selectedVersion);
            window.location.href = url;
        });

        function initializeSwaggerUI() {
            var data = @json($response);
            const jsonContent = jsyaml.load(data);
            renderSwaggerUI(data);

            function renderSwaggerUI(jsonContent) {
                const ui = SwaggerUIBundle({
                    spec: jsonContent,
                    dom_id: "#swagger-ui",
                    deepLinking: true,
                    presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
                    plugins: [SwaggerUIBundle.plugins.DownloadUrl],
                    layout: "BaseLayout",
                });
            }
        }
        $(document).ready(function() {
            initializeSwaggerUI();
        });
    </script>
</body>

</html>
