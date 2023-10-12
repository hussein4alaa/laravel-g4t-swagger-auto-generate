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

    <div class="openapi-nav">
        <ul class="nav-ul">
            <li>
                <a target="_blank" href="{{ route('swagger.json') }}" class="link-swagger">Download Swagger File</a>
                <a onclick="openModal()" class="link-swagger">Set Global Authorization</a>
            </li>
        </ul>
    </div>

    <div class="global-token-container" id="myModal">
        <span class="global-title">Set Global Authorization</span>
        <hr>
        <div id="key-cont" class="authTokenInput-cont">
            <label for="authTokenKey">Auth Key</label>
            <input type="text" class="global-token" id="authTokenKey" placeholder="Enter Authorization Key"
                value="Authorization">
        </div>
        <div id="value-cont" class="authTokenInput-cont">
            <label for="authTokenKey">Auth Value</label>
            <input type="text" class="global-token" id="authTokenInput" placeholder="Enter Authorization Token">
        </div>

        <div class="action-btns">
            <button class="btn btn-auth" onclick="saveAuthToken()">Authorize</button>
            <button class="btn btn-logout" onclick="logoutToken()">Logout</button>
            <button class="btn btn-logout" onclick="closeModal()">Close</button>
        </div>
    </div>

    <div id="overlay"></div>


    <div id="swagger-ui"></div>

    <script>
        function openModal() {
            const modal = document.getElementById('myModal');
            const overlay = document.getElementById('overlay');
            modal.style.display = 'block';
            overlay.style.display = 'block';
        }

        function closeModal() {
            const modal = document.getElementById('myModal');
            const overlay = document.getElementById('overlay');
            modal.style.display = 'none';
            overlay.style.display = 'none';
        }

        function saveAuthToken() {
            const authToken = document.getElementById('authTokenInput').value;
            const authTokenKey = document.getElementById('authTokenKey').value;
            localStorage.setItem('authorizationToken', authToken);
            localStorage.setItem('authorizationKey', authTokenKey);
            document.getElementById('key-cont').style.display = 'none';
            document.getElementById('value-cont').style.display = 'none';
            initializeSwaggerUI();
        }

        function logoutToken() {
            localStorage.removeItem('authorizationToken');
            localStorage.removeItem('authorizationKey');
            document.getElementById('key-cont').style.display = 'inline';
            document.getElementById('value-cont').style.display = 'inline';
            initializeSwaggerUI();
        }

        function getAuthTokenFromLocalStorage() {
            return localStorage.getItem('authorizationToken');
        }

        function getAuthKeyFromLocalStorage() {
            return localStorage.getItem('authorizationKey');
        }


        function initializeSwaggerUI() {
            const authToken = getAuthTokenFromLocalStorage();
            const authKey = getAuthKeyFromLocalStorage();
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
                    requestInterceptor: (request) => {
                        if (authToken && authKey) {
                            request.headers[authKey] = `Bearer ${authToken}`;
                        }
                        request.headers['is-swagger'] = 'true';
                        return request;
                    }

                });
            }
        }
        $(document).ready(function() {
            initializeSwaggerUI();
            if (getAuthTokenFromLocalStorage()) {
                document.getElementById('key-cont').style.display = 'none';
                document.getElementById('value-cont').style.display = 'none';
            }
        });
    </script>
</body>

</html>
