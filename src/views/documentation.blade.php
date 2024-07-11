<!doctype html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name') }} - G4T Swagger</title>

    <link rel="stylesheet" href="{{ asset('g4t/swagger/css/swagger-ui.css') }}">
    <link href="{{ asset('g4t/swagger/css/bootstrap.min.css') }}" rel="stylesheet"
        integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <style>
        html {
            box-sizing: border-box;
        }

        *,
        *:before,
        *:after {
            box-sizing: inherit;
        }

        body {
            margin: 0;
            background: #fafafa;
        }

        img.theme-png {
            width: 100%;
            height: 100%;
        }

        button.btn.btn-secondary.theme-btn {
            width: 60px;
            border-radius: 1pc;
            height: 48px;
            position: fixed;
            margin: 6px;
            background: #85ea2d;
            z-index: 999;
            border: 0;
            box-shadow: 0px 0px 4px 1px #959595;
        }

        .modal.left .modal-dialog {
            position: fixed;
            margin: 0;
            width: 300px;
            /* You can adjust the width as needed */
            height: 100%;
            top: 0;
            left: -300px;
            /* Start offscreen */
            transition: left 0.3s ease;
        }

        .modal.left.show .modal-dialog {
            left: 0;
            /* Slide in from left when modal is shown */
        }

        /* Ensure the modal covers the entire viewport */
        .modal.left .modal-content {
            height: 100%;
            border-radius: 0;
        }

        /* Adjust modal body height if needed */
        .modal.left .modal-body {
            overflow-y: auto;
            /* Enable vertical scrolling if content exceeds height */
            max-height: calc(100% - 120px);
            /* Adjust to leave space for header and footer */
        }

        .theme-btn-list {
            width: 100%;
            margin-bottom: 5px;
        }

        li {
            list-style: none;
        }
    </style>


</head>

<body>

    <!-- Button trigger modal -->
    <button type="button" class="btn btn-secondary theme-btn" data-bs-toggle="modal" data-bs-target="#exampleModal">
        <img src="{{ asset('g4t/swagger/images/theme.png') }}" class="theme-png">
    </button>

    <!-- Modal -->
    <div class="modal fade left" id="exampleModal" tabindex="-1" aria-labelledby="exampleModalLabel"
        aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="exampleModalLabel">Change theme</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <ul style="padding:0;">
                        @foreach ($themes as $theme)
                            <li>
                                <button class="btn btn-secondary theme-btn-list"
                                    onclick='changeTheme("{{ $theme }}")'>{{ $theme }}</button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>



    <div id="swagger-ui"></div>

    <script src="{{ asset('g4t/swagger/js/swagger-ui-bundle.js') }}"></script>
    <script src="{{ asset('g4t/swagger/js/swagger-ui-standalone-preset.js') }}"></script>

    <script>
        function changeTheme(theme) {
            var themePath = {!! json_encode($themes_path) !!} + '/' + theme + '.css';
            var customStyle = document.getElementById('custom-style');
    
            // Save the selected theme in local storage
            localStorage.setItem('selectedTheme', themePath);
    
            if (customStyle) {
                customStyle.innerHTML = '@import "' + themePath + '"';
            } else {
                var style = document.createElement('style');
                style.id = 'custom-style';
                style.innerHTML = '@import "' + themePath + '"';
                document.head.appendChild(style);
            }
        }
    
        window.onload = function() {
            // Get the selected theme from local storage
            var selectedTheme = localStorage.getItem('selectedTheme');
    
            // Apply the selected theme if it exists in local storage
            if (selectedTheme) {
                var customStyle = document.getElementById('custom-style');
                if (customStyle) {
                    customStyle.innerHTML = '@import "' + selectedTheme + '"';
                } else {
                    var style = document.createElement('style');
                    style.id = 'custom-style';
                    style.innerHTML = '@import "' + selectedTheme + '"';
                    document.head.appendChild(style);
                }
            }
    
            window.ui = SwaggerUIBundle({
                urls: [
                    @foreach ($versions['versions'] as $version)
                        {
                            url: '{{ $version['url'] }}',
                            name: '{{ $version['name'] }}',
                        },
                    @endforeach
                ],
                "urls.primaryName": "{{ $versions['default'] }}",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                layout: 'StandaloneLayout',
                requestInterceptor: (request) => {
                    request.headers['accept'] = 'application/json';
                    return request;
                },
                responseInterceptor: (response) => {
                    response.headers['content-type'] = 'application/json';
                    response.headers['accept'] = 'application/json';
                    return response;
                }
            });
        };
    </script>
    
</body>

<script src="{{ asset('g4t/swagger/js/popper.min.js') }}"
    integrity="sha384-IQsoLXl5PILFhosVNubq5LC7Qb9DXgDA9i+tQ8Zj3iwWAwPtgFTxbJ8NT4GN1R8p" crossorigin="anonymous">
</script>
<script src="{{ asset('g4t/swagger/js/bootstrap.min.js') }}"
    integrity="sha384-cVKIPhGWiC2Al4u+LWgxfKTRIcfu0JTxR+EQDz/bgldoEyl4H0zUF0QKbrJ0EcQF" crossorigin="anonymous">
</script>

</html>
