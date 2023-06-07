<!DOCTYPE html>
<html>

<head>
    <title>Swagger JSON Reader</title>
    <script src="{{ asset('g4t/swagger/js/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('g4t/swagger/js/js-yaml.min.js') }}"></script>
    <script src="{{ asset('g4t/swagger/js/swagger-ui-bundle.js') }}"></script>
    <link rel="stylesheet" type="text/css" href="{{ asset('g4t/swagger/css/swagger-ui.css') }}" />
    <link rel="stylesheet" href="{{ asset('g4t/swagger/css/style.css') }}">
</head>

<body>

    <div class="openapi-icon">
        <a href="{{ route('swagger.json') }}" class="link-swagger">OpenAPI 3.0.3</a>
    </div>
    <div id="swagger-ui"></div>

    <script>
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
    </script>
</body>

</html>
