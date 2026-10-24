{{-- Login button for "Try it out": stores the token and exposes window.G4TAuth to the UI scripts. --}}
@if ($autoLogin)
    <style>
        .g4t-login-toggle {
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 10000;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border: 0;
            border-radius: 999px;
            background: #1f2937;
            color: #fff;
            font: 600 14px/1 system-ui, -apple-system, "Segoe UI", sans-serif;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .25);
            cursor: pointer;
        }

        .g4t-login-toggle.is-authenticated {
            background: #15803d;
        }

        .g4t-login-dialog {
            width: min(360px, calc(100vw - 32px));
            padding: 0;
            border: 0;
            border-radius: 12px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .3);
            font: 14px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif;
            /* Fixed light palette so dark-mode UIs (Scalar) don't inherit into the dialog. */
            color-scheme: light;
            background: #fff;
            color: #111827;
        }

        .g4t-login-dialog input {
            background: #fff;
            color: #111827;
        }

        .g4t-login-dialog::backdrop {
            background: rgba(17, 24, 39, .45);
        }

        .g4t-login-dialog form {
            display: grid;
            gap: 12px;
            padding: 20px;
        }

        .g4t-login-dialog h2 {
            margin: 0;
            font-size: 18px;
        }

        .g4t-login-dialog label {
            display: grid;
            gap: 4px;
            font-weight: 600;
        }

        .g4t-login-dialog input {
            padding: 9px 10px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font: inherit;
        }

        .g4t-login-dialog .g4t-login-error {
            margin: 0;
            color: #b91c1c;
            white-space: pre-line;
        }

        .g4t-login-dialog .g4t-login-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .g4t-login-dialog button {
            padding: 9px 14px;
            border: 0;
            border-radius: 8px;
            font: 600 14px/1 inherit;
            cursor: pointer;
        }

        .g4t-login-dialog button[type="submit"] {
            background: #1f2937;
            color: #fff;
        }

        .g4t-login-dialog button[disabled] {
            opacity: .6;
            cursor: wait;
        }
    </style>

    <button type="button" class="g4t-login-toggle" id="g4t-login-toggle"></button>

    <dialog class="g4t-login-dialog" id="g4t-login-dialog">
        <form id="g4t-login-form" method="dialog">
            <h2>Login</h2>
            @foreach ($autoLogin['fields'] as $field => $label)
                <label>
                    {{ $label }}
                    <input name="{{ $field }}"
                        type="{{ str_contains($field, 'password') ? 'password' : (str_contains($field, 'email') ? 'email' : 'text') }}"
                        value="{{ $autoLogin['prefill'][$field] ?? '' }}"
                        autocomplete="{{ str_contains($field, 'password') ? 'current-password' : 'username' }}" required>
                </label>
            @endforeach
            <p class="g4t-login-error" id="g4t-login-error" hidden></p>
            <div class="g4t-login-actions">
                <button type="button" id="g4t-login-cancel">Cancel</button>
                <button type="submit" id="g4t-login-submit">Login</button>
            </div>
        </form>
    </dialog>

    <script>
        window.G4TAuth = (function(settings) {
            var storageKey = 'g4t-swagger:token:' + settings.url;
            var listeners = [];
            var fallbackPaths = ['token', 'access_token', 'accessToken', 'plainTextToken', 'data.token',
                'data.access_token', 'data.accessToken', 'data.plainTextToken', 'token.plainTextToken'
            ];
            var toggle = document.getElementById('g4t-login-toggle');
            var dialog = document.getElementById('g4t-login-dialog');
            var form = document.getElementById('g4t-login-form');
            var errorBox = document.getElementById('g4t-login-error');
            var submit = document.getElementById('g4t-login-submit');

            function read() {
                try {
                    return localStorage.getItem(storageKey);
                } catch (e) {
                    return null;
                }
            }

            function write(token) {
                try {
                    token ? localStorage.setItem(storageKey, token) : localStorage.removeItem(storageKey);
                } catch (e) {}
            }

            var token = read();

            function dig(payload, path) {
                return path.split('.').reduce(function(value, key) {
                    return value && typeof value === 'object' ? value[key] : undefined;
                }, payload);
            }

            function findToken(payload) {
                var paths = settings.tokenPath ? [settings.tokenPath].concat(fallbackPaths) : fallbackPaths;
                for (var i = 0; i < paths.length; i++) {
                    var value = dig(payload, paths[i]);
                    if (typeof value === 'string' && value !== '') {
                        return value;
                    }
                }
                return null;
            }

            function errorMessage(payload, status) {
                if (payload && payload.errors && typeof payload.errors === 'object') {
                    return Object.keys(payload.errors).map(function(key) {
                        return [].concat(payload.errors[key]).join('\n');
                    }).join('\n');
                }
                return (payload && payload.message) || ('Login failed (HTTP ' + status + ').');
            }

            function render() {
                toggle.textContent = token ? '✓ Logged in · Logout' : '🔒 Login';
                toggle.classList.toggle('is-authenticated', !!token);
            }

            function setToken(value) {
                token = value;
                write(value);
                render();
                listeners.forEach(function(listener) {
                    listener(token);
                });
            }

            function showError(message) {
                errorBox.textContent = message;
                errorBox.hidden = !message;
            }

            toggle.addEventListener('click', function() {
                if (token) {
                    setToken(null);
                    return;
                }
                showError('');
                dialog.showModal();
            });

            document.getElementById('g4t-login-cancel').addEventListener('click', function() {
                dialog.close();
            });

            form.addEventListener('submit', function(event) {
                event.preventDefault();
                var body = {};
                new FormData(form).forEach(function(value, key) {
                    body[key] = value;
                });

                submit.disabled = true;
                showError('');
                fetch(settings.url, {
                        method: settings.method,
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(body)
                    })
                    .then(function(response) {
                        return response.json().catch(function() {
                            return null;
                        }).then(function(payload) {
                            if (!response.ok) {
                                throw new Error(errorMessage(payload, response.status));
                            }
                            var found = findToken(payload);
                            if (!found) {
                                throw new Error(
                                    'Logged in, but no token was found in the response. Set swagger.auto_login.token_path.'
                                );
                            }
                            setToken(found);
                            dialog.close();
                        });
                    })
                    .catch(function(error) {
                        showError(error.message);
                    })
                    .finally(function() {
                        submit.disabled = false;
                    });
            });

            render();

            return {
                scheme: settings.scheme,
                token: function() {
                    return token;
                },
                onChange: function(listener) {
                    listeners.push(listener);
                }
            };
        })({!! json_encode($autoLogin, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!});
    </script>
@endif
