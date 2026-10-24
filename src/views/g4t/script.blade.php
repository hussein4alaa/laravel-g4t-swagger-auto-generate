@verbatim
<script>
(function () {
    'use strict';

    var S = window.G4T_SETTINGS || {};
    var STATUS = { 0: 'Network error', 200: 'OK', 201: 'Created', 202: 'Accepted', 204: 'No Content', 301: 'Moved', 302: 'Found', 304: 'Not Modified', 400: 'Bad Request', 401: 'Unauthorized', 402: 'Payment Required', 403: 'Forbidden', 404: 'Not Found', 405: 'Method Not Allowed', 409: 'Conflict', 410: 'Gone', 413: 'Too Large', 415: 'Unsupported Media', 419: 'Page Expired', 422: 'Unprocessable', 429: 'Too Many Requests', 500: 'Server Error', 502: 'Bad Gateway', 503: 'Unavailable' };
    // Accent per theme name (light, dark). Unknown themes fall back to the default accent.
    var PALETTES = {
        'default': ['#4f46e5', '#a5b4fc'], 'theme-feeling-blue': ['#1d64c8', '#8fb6ff'], 'theme-flattop': ['#0f766e', '#5eead4'],
        'theme-material': ['#6d28d9', '#c4b5fd'], 'theme-monokai': ['#8a5a00', '#f5d06b'], 'theme-muted': ['#52525b', '#d4d4d8'],
        'theme-newspaper': ['#9f1239', '#fda4af'], 'theme-outline': ['#18181b', '#f4f4f5']
    };
    var METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];
    var ICON = {
        lock: '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="11" width="16" height="10" rx="2"></rect><path d="M8 11V7a4 4 0 0 1 8 0v4"></path></svg>',
        chev: '<svg class="g4t-chev" aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"></path></svg>',
        chevSm: '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m6 9 6 6 6-6"></path></svg>',
        copy: '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"></rect><path d="M5 15V5a2 2 0 0 1 2-2h10"></path></svg>',
        term: '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 8 4 4-4 4M12 16h7"></path></svg>',
        key: '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"></circle><path d="m10.8 12.2 9.2-9.2M17 6l3 3M14 9l2 2"></path></svg>',
        warn: '<svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 2 20h20L12 3z"></path><path d="M12 10v4M12 17.5v.01"></path></svg>'
    };

    var store = {
        get: function (key, fallback) { try { var v = localStorage.getItem('g4t-ui:' + key); return v === null ? fallback : v; } catch (e) { return fallback; } },
        set: function (key, value) { try { localStorage.setItem('g4t-ui:' + key, value); } catch (e) { } }
    };
    // Same key as the Login button of the Swagger UI / Scalar pages, so a token carries over between UIs.
    var tokenKey = 'g4t-swagger:token:' + (S.autoLogin ? S.autoLogin.url : 'manual');
    function readToken() { try { return localStorage.getItem(tokenKey); } catch (e) { return null; } }
    function writeToken(token) { try { token ? localStorage.setItem(tokenKey, token) : localStorage.removeItem(tokenKey); } catch (e) { } }

    var versionNames = ((S.versions && S.versions.versions) || []).map(function (v) { return v.name; });
    var savedVersion = store.get('version', null);

    var state = {
        spec: null, model: null, loading: true, error: null,
        version: versionNames.indexOf(savedVersion) !== -1 ? savedVersion : ((S.versions && S.versions.default) || versionNames[0] || 'all'),
        filter: '',
        mode: store.get('mode', S.mode || 'light'),
        density: store.get('density', S.density || 'comfortable'),
        theme: store.get('theme', S.defaultTheme || 'default'),
        drawer: false, appearOpen: false, authMenuOpen: false,
        loginOpen: false, loggingIn: false, loginError: '', loginValues: {}, loginTab: S.autoLogin ? 'credentials' : 'token',
        token: readToken(), server: null, active: null,
        expanded: {}, trying: {}, respTab: {}, exTab: {}, respView: {}, ctype: {},
        bodies: {}, inputs: {}, forms: {}, files: {}, busy: {}, results: {}
    };

    var $ = function (id) { return document.getElementById(id); };
    var els = { app: document.querySelector('.g4t-app'), version: $('g4t-version'), auth: $('g4t-auth'), appear: $('g4t-appear'), appearBtn: $('g4t-appear-btn'), filter: $('g4t-filter'), nav: $('g4t-nav'), info: $('g4t-info'), ops: $('g4t-ops'), login: $('g4t-login'), toast: $('g4t-toast'), scrim: document.querySelector('.g4t-scrim'), drawerBtn: document.querySelector('.g4t-drawer-btn') };

    /* ---------- helpers ---------- */

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function slug(value) { return String(value).replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'op'; }
    function statusClass(code) { return code === 0 ? 'g4t-err' : code < 300 ? 'g4t-ok' : code < 400 ? 'g4t-neu' : code < 500 ? 'g4t-warn' : 'g4t-err'; }
    function statusText(code, fallback) { return STATUS[code] || fallback || ''; }
    function isDark() { return state.mode === 'dark' || (state.mode === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); }
    function spinner() { return '<span class="g4t-spinner" aria-hidden="true"></span>'; }
    function versionUrl(name) {
        var found = ((S.versions && S.versions.versions) || []).filter(function (v) { return v.name === name; })[0];
        return found ? found.url : S.jsonUrl + '?version=' + encodeURIComponent(name);
    }

    /** JSON → highlighted HTML (every token escaped). */
    function highlight(text) {
        var re = /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|[{}\[\],]/g;
        var out = '', last = 0, m;
        while ((m = re.exec(text))) {
            out += esc(text.slice(last, m.index));
            if (m[1]) {
                out += '<span class="' + (m[2] ? 'j-key' : 'j-str') + '">' + esc(m[1]) + '</span>';
                if (m[2]) { out += '<span class="j-punc">' + esc(m[2]) + '</span>'; }
            } else if (m[3]) {
                out += '<span class="j-kw">' + m[0] + '</span>';
            } else if (/^[{}\[\],]$/.test(m[0])) {
                out += '<span class="j-punc">' + m[0] + '</span>';
            } else {
                out += '<span class="j-num">' + m[0] + '</span>';
            }
            last = re.lastIndex;
        }
        return out + esc(text.slice(last));
    }
    function pretty(value) { return JSON.stringify(value, null, 2); }

    var toastTimer = null;
    function toast(message) {
        clearTimeout(toastTimer);
        els.toast.textContent = message;
        els.toast.hidden = false;
        toastTimer = setTimeout(function () { els.toast.hidden = true; }, 1800);
    }
    function copy(text, message) {
        var done = function () { toast(message); };
        var fallback = function () {
            var t = document.createElement('textarea');
            t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
            document.body.appendChild(t); t.select();
            try { document.execCommand('copy'); } catch (e) { }
            t.remove(); done();
        };
        try { navigator.clipboard.writeText(text).then(done, fallback); } catch (e) { fallback(); }
    }

    /* ---------- OpenAPI model ---------- */

    function resolveRef(ref) {
        if (typeof ref !== 'string' || ref.indexOf('#/') !== 0) { return null; }
        return ref.slice(2).split('/').reduce(function (node, key) { return node == null ? null : node[key.replace(/~1/g, '/').replace(/~0/g, '~')]; }, state.spec);
    }
    /** Follows $ref chains; returns { schema, name } (name of the last referenced component). */
    function resolve(schema, seen) {
        seen = seen || [];
        var name = null;
        while (schema && schema.$ref && seen.indexOf(schema.$ref) === -1) {
            seen.push(schema.$ref);
            name = schema.$ref.split('/').pop();
            schema = resolveRef(schema.$ref);
        }
        return { schema: schema || {}, name: name };
    }
    function schemaType(schema) {
        if (schema.type) { return schema.type; }
        if (schema.properties) { return 'object'; }
        if (schema.items) { return 'array'; }
        if (schema.enum) { return 'string'; }
        return 'any';
    }
    function typeLabel(raw) {
        var r = resolve(raw), s = r.schema, type = schemaType(s);
        if (s.enum) { return 'enum'; }
        if (type === 'array') {
            var items = resolve(s.items || {});
            return 'array<' + (items.name || (items.schema.format === 'binary' ? 'file' : schemaType(items.schema))) + '>';
        }
        if (r.name && type === 'object') { return r.name; }
        if (s.format === 'binary') { return 'file'; }
        return s.format ? type + ' <' + s.format + '>' : type;
    }
    function noteFor(raw) {
        var s = resolve(raw).schema, parts = [];
        if (s.description) { parts.push(s.description); }
        if (s.enum) { parts.push(s.enum.join(' · ')); }
        var min = s.minLength != null ? s.minLength : s.minimum != null ? s.minimum : s.minItems;
        var max = s.maxLength != null ? s.maxLength : s.maximum != null ? s.maximum : s.maxItems;
        if (min != null) { parts.push('min ' + min); }
        if (max != null) { parts.push('max ' + max); }
        if (s.default !== undefined) { parts.push('default ' + JSON.stringify(s.default)); }
        if (s.nullable) { parts.push('nullable'); }
        if (s.deprecated) { parts.push('deprecated'); }
        return parts.join(' · ') || '—';
    }
    /** Flattens a schema's properties into rows; nested objects / arrays of objects are indented. */
    function fields(raw, level, out, seen) {
        out = out || []; seen = seen || [];
        var r = resolve(raw), s = r.schema;
        if (r.name) { if (seen.indexOf(r.name) !== -1) { return out; } seen = seen.concat([r.name]); }
        var required = s.required || [];
        Object.keys(s.properties || {}).forEach(function (key) {
            var prop = s.properties[key];
            out.push({ name: key, type: typeLabel(prop), req: required.indexOf(key) !== -1, note: noteFor(prop), level: level || 0 });
            if ((level || 0) >= 3) { return; }
            var child = resolve(prop).schema;
            if (schemaType(child) === 'object' && child.properties) {
                fields(prop, (level || 0) + 1, out, seen);
            } else if (schemaType(child) === 'array' && child.items) {
                var item = resolve(child.items).schema;
                if (item.properties) { fields(child.items, (level || 0) + 1, out, seen); }
            }
        });
        return out;
    }
    /** Example value for a schema (example → default → enum → by type). */
    function sample(raw, depth, seen) {
        depth = depth || 0; seen = seen || [];
        var r = resolve(raw), s = r.schema;
        if (r.name) { if (seen.indexOf(r.name) !== -1) { return {}; } seen = seen.concat([r.name]); }
        if (s.example !== undefined) { return s.example; }
        if (s.default !== undefined) { return s.default; }
        if (s.enum && s.enum.length) { return s.enum[0]; }
        var type = schemaType(s);
        if (type === 'object') {
            var obj = {};
            if (depth < 4) { Object.keys(s.properties || {}).forEach(function (k) { obj[k] = sample(s.properties[k], depth + 1, seen); }); }
            return obj;
        }
        if (type === 'array') { return depth < 4 && s.items ? [sample(s.items, depth + 1, seen)] : []; }
        if (type === 'integer') { return s.minimum != null ? s.minimum : 1; }
        if (type === 'number') { return s.minimum != null ? s.minimum : 1.5; }
        if (type === 'boolean') { return true; }
        if (s.format === 'email') { return 'user@example.com'; }
        if (s.format === 'date') { return '2024-01-01'; }
        if (s.format === 'date-time') { return '2024-01-01T12:00:00Z'; }
        if (s.format === 'uuid') { return '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'; }
        return 'string';
    }
    function isBinary(raw) {
        var s = resolve(raw).schema;
        return s.format === 'binary' || (schemaType(s) === 'array' && s.items && resolve(s.items).schema.format === 'binary');
    }

    function buildModel(spec) {
        var ops = [], byTag = {}, tagOrder = [], tagDesc = {};
        (spec.tags || []).forEach(function (t) { if (t && t.name) { tagOrder.push(t.name); tagDesc[t.name] = t.description || ''; } });
        var globalSecurity = Array.isArray(spec.security) && spec.security.length > 0;
        var used = {};
        Object.keys(spec.paths || {}).forEach(function (path) {
            var item = spec.paths[path] || {};
            METHODS.forEach(function (m) {
                var o = item[m];
                if (!o) { return; }
                var id = slug(o.operationId || (m + '_' + path));
                while (used[id]) { id += '_'; }
                used[id] = true;
                var params = (item.parameters || []).concat(o.parameters || []).map(function (p) { return p.$ref ? resolveRef(p.$ref) : p; }).filter(Boolean);
                var body = o.requestBody && o.requestBody.$ref ? resolveRef(o.requestBody.$ref) : o.requestBody;
                var op = {
                    id: id, method: m.toUpperCase(), path: path, tag: (o.tags && o.tags[0]) || 'default',
                    summary: o.summary || '', desc: o.description || '', deprecated: !!o.deprecated,
                    secured: Array.isArray(o.security) ? o.security.length > 0 : globalSecurity,
                    params: params, body: body && body.content ? body : null, responses: buildResponses(o.responses || {})
                };
                op.ctypes = op.body ? Object.keys(op.body.content) : [];
                if (op.body && op.ctypes.length > 1) {
                    // Uploads read best as multipart; everything else as JSON.
                    var hasFile = op.ctypes.some(function (c) { var sc = resolve(op.body.content[c].schema || {}).schema; return Object.keys(sc.properties || {}).some(function (k) { return isBinary(sc.properties[k]); }); });
                    var preferred = hasFile ? 'multipart/form-data' : 'application/json';
                    op.ctypes.sort(function (a, b) { return (b === preferred) - (a === preferred); });
                }
                ops.push(op);
                if (!byTag[op.tag]) { byTag[op.tag] = []; if (tagOrder.indexOf(op.tag) === -1) { tagOrder.push(op.tag); } }
                byTag[op.tag].push(op);
            });
        });
        var tags = tagOrder.filter(function (n) { return byTag[n]; }).map(function (n) { return { name: n, desc: tagDesc[n] || '', anchor: 'tag-' + slug(n), ops: byTag[n] }; });
        var schemes = (spec.components && spec.components.securitySchemes) || {};
        var servers = (spec.servers || []).filter(function (s) { return s && s.url; }).map(function (s) { return { url: String(s.url).replace(/\/+$/, ''), label: s.description || '' }; });
        if (!servers.some(function (s) { return s.url === location.origin; })) { servers.push({ url: location.origin, label: 'This page' }); }
        return { ops: ops, tags: tags, schemes: schemes, servers: servers };
    }

    function buildResponses(responses) {
        return Object.keys(responses).sort(function (a, b) { return (parseInt(a, 10) || 999) - (parseInt(b, 10) || 999); }).map(function (code) {
            var r = responses[code] && responses[code].$ref ? resolveRef(responses[code].$ref) : responses[code];
            r = r || {};
            var json = r.content && (r.content['application/json'] || r.content[Object.keys(r.content)[0]]);
            var examples = [];
            if (json && json.example !== undefined) {
                examples.push({ name: 'Example', value: json.example, has: true });
            } else if (json && json.examples) {
                Object.keys(json.examples).forEach(function (k) {
                    var ex = json.examples[k] && json.examples[k].$ref ? resolveRef(json.examples[k].$ref) : json.examples[k];
                    if (ex) { examples.push({ name: ex.summary || k, value: ex.value, has: true }); }
                });
            }
            if (!examples.length && json && json.schema) { examples.push({ name: 'Example', value: sample(json.schema), has: true, generated: true }); }
            if (!examples.length) { examples.push({ name: 'Example', value: null, has: false }); }
            var num = parseInt(code, 10);
            return { code: isNaN(num) ? code : num, desc: r.description || '', examples: examples, schema: json && json.schema ? json.schema : null };
        });
    }

    /* ---------- appearance ---------- */

    function applyRoot() {
        var root = document.documentElement, dark = isDark();
        root.dataset.mode = dark ? 'dark' : 'light';
        root.dataset.density = state.density;
        var palette = PALETTES[state.theme] || PALETTES['default'];
        root.style.setProperty('--accent', palette[dark ? 1 : 0]);
        root.style.setProperty('--accent-fg', dark ? '#0f1012' : '#ffffff');
    }

    function renderAppear() {
        els.appear.hidden = !state.appearOpen;
        els.appearBtn.setAttribute('aria-expanded', state.appearOpen ? 'true' : 'false');
        if (!state.appearOpen) { return; }
        var seg = function (list, current, action) {
            return list.map(function (o) { return '<button type="button" role="radio" aria-checked="' + (o[0] === current) + '" data-action="' + action + '" data-value="' + esc(o[0]) + '">' + esc(o[1]) + '</button>'; }).join('');
        };
        var dark = isDark();
        var themes = (S.themes && S.themes.length ? S.themes : Object.keys(PALETTES)).map(function (name) {
            var on = name === state.theme, palette = PALETTES[name] || PALETTES['default'];
            return '<button type="button" role="radio" aria-checked="' + on + '" data-action="theme" data-value="' + esc(name) + '"><span class="g4t-swatch" style="background:' + esc(palette[dark ? 1 : 0]) + '"></span><span class="g4t-theme-name">' + esc(name) + '</span><span class="g4t-check" aria-hidden="true">' + (on ? '✓' : '') + '</span></button>';
        }).join('');
        els.appear.innerHTML =
            '<div style="display:grid;gap:6px"><div class="g4t-menu-label">Mode</div><div class="g4t-seg" role="radiogroup" aria-label="Mode" style="grid-template-columns:repeat(3,1fr)">' + seg([['light', 'Light'], ['dark', 'Dark'], ['system', 'System']], state.mode, 'mode') + '</div></div>' +
            '<div style="display:grid;gap:6px"><div class="g4t-menu-label">Density</div><div class="g4t-seg" role="radiogroup" aria-label="Density" style="grid-template-columns:repeat(2,1fr)">' + seg([['comfortable', 'Comfortable'], ['compact', 'Compact']], state.density, 'density') + '</div></div>' +
            '<div style="display:grid;gap:6px"><div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px"><span class="g4t-menu-label">Theme</span><span class="g4t-menu-hint">accent color</span></div><div class="g4t-theme-list" role="radiogroup" aria-label="Theme">' + themes + '</div></div>';
    }

    /* ---------- auth ---------- */

    function securedCount() { return state.model ? state.model.ops.filter(function (o) { return o.secured; }).length : 0; }
    function schemeLabel() {
        var schemes = state.model ? state.model.schemes : {};
        var first = schemes[Object.keys(schemes)[0]];
        if (!first) { return 'Auth'; }
        if (first.type === 'http' && /bearer/i.test(first.scheme || '')) { return 'Bearer auth'; }
        if (first.type === 'http') { return (first.scheme || 'HTTP') + ' auth'; }
        if (first.type === 'apiKey') { return 'API key (' + (first.in || 'header') + ')'; }
        return first.type || 'Auth';
    }
    function schemeName() { return (S.autoLogin && S.autoLogin.scheme) || (state.model && Object.keys(state.model.schemes)[0]) || 'bearerAuth'; }

    function renderAuth() {
        if (!state.token) {
            els.auth.innerHTML = '<button type="button" id="g4t-login-toggle" class="g4t-auth-btn g4t-login-toggle" data-action="open-login" aria-label="Log in"><span class="g4t-dot" aria-hidden="true"></span><span class="g4t-auth-text g4t-auth-text-out"><span style="color:var(--text-2)">Not signed in</span><span class="g4t-auth-sep" aria-hidden="true"></span></span><span class="g4t-auth-cta">' + ICON.lock.replace('width="14" height="14"', 'width="13" height="13"') + 'Log in</span></button>';
            return;
        }
        var t = state.token, preview = t.length > 20 ? t.slice(0, 10) + '…' + t.slice(-6) : t;
        els.auth.innerHTML = '<button type="button" id="g4t-login-toggle" class="g4t-auth-btn g4t-login-toggle is-authenticated" data-action="toggle-auth-menu" aria-haspopup="menu" aria-expanded="' + state.authMenuOpen + '"><span class="g4t-dot" aria-hidden="true"></span><span class="g4t-auth-text">Authenticated</span>' + ICON.chevSm + '</button>' +
            (state.authMenuOpen ? '<div class="g4t-menu" role="menu"><div style="display:grid;gap:4px"><div class="g4t-menu-hint" style="font-size:12px">Bearer token · ' + esc(schemeName()) + '</div><div class="g4t-token-box">' + esc(preview) + '</div><div style="font-size:12px;color:var(--text-2)">Sent with ' + securedCount() + ' protected operations</div></div><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px"><button type="button" role="menuitem" class="g4t-btn" data-action="copy-token">' + ICON.copy + 'Copy token</button><button type="button" role="menuitem" class="g4t-btn" data-action="open-token">' + ICON.key + 'Change token</button><button type="button" role="menuitem" class="g4t-btn g4t-btn-danger" style="grid-column:1 / -1" data-action="logout">Log out</button></div></div>' : '');
    }

    function renderLogin() {
        if (!state.loginOpen) { els.login.innerHTML = ''; return; }
        var auto = S.autoLogin, tokenTab = !auto || state.loginTab === 'token', fieldsHtml = '', intro, submitLabel;
        if (tokenTab) {
            var current = state.loginValues.token != null ? state.loginValues.token : (state.token || '');
            fieldsHtml = '<label>Bearer token<div class="g4t-token-field"><input name="token" data-bind="login" type="text" class="g4t-mono" autocomplete="off" spellcheck="false" required placeholder="1|aBcDeF…" value="' + esc(current) + '" data-autofocus>' +
                '<button type="button" class="g4t-btn" data-action="paste-token" title="Paste from clipboard">' + ICON.copy + 'Paste</button></div></label>';
            intro = 'Sent as <code class="g4t-mono">Authorization: Bearer &lt;token&gt;</code> with protected requests and stored in this browser. A leading “Bearer ” is removed.';
            submitLabel = state.token ? 'Save token' : 'Authorize';
        } else {
            Object.keys(auto.fields || {}).forEach(function (name, i) {
                var isPassword = /password/i.test(name), isEmail = /email/i.test(name);
                var value = state.loginValues[name] != null ? state.loginValues[name] : ((auto.prefill || {})[name] || '');
                fieldsHtml += '<label>' + esc(auto.fields[name]) + '<input name="' + esc(name) + '" data-bind="login" type="' + (isPassword ? 'password' : isEmail ? 'email' : 'text') + '" autocomplete="' + (isPassword ? 'current-password' : 'username') + '" required value="' + esc(value) + '"' + (i === 0 ? ' data-autofocus' : '') + '></label>';
            });
            intro = esc(auto.method + ' ' + auto.url.replace(location.origin, '')) + ' — the token is stored in this browser and sent with protected requests.';
            submitLabel = 'Log in';
        }
        var tabs = auto ? '<div class="g4t-seg" role="tablist" aria-label="Sign-in method" style="grid-template-columns:repeat(2,1fr)">' +
            '<button type="button" role="tab" aria-selected="' + !tokenTab + '" data-action="login-tab" data-value="credentials">Log in</button>' +
            '<button type="button" role="tab" aria-selected="' + tokenTab + '" data-action="login-tab" data-value="token">Bearer token</button></div>' : '';
        els.login.innerHTML = '<div class="g4t-modal" data-action="close-login-backdrop"><div id="g4t-login-dialog" class="g4t-login-dialog" role="dialog" aria-modal="true" aria-labelledby="g4t-login-title"><form id="g4t-login-form" novalidate>' +
            '<div style="display:grid;gap:4px"><h2 id="g4t-login-title">' + (tokenTab ? 'Authorize' : 'Log in') + '</h2><p>' + intro + '</p></div>' + tabs + fieldsHtml +
            (state.loginError ? '<p id="g4t-login-error" class="g4t-login-error" role="alert">' + esc(state.loginError) + '</p>' : '') +
            '<div class="g4t-actions"><button type="button" id="g4t-login-cancel" class="g4t-btn" data-action="close-login">Cancel</button><button type="submit" id="g4t-login-submit" class="g4t-btn g4t-btn-primary"' + (state.loggingIn ? ' disabled' : '') + '>' + (state.loggingIn ? spinner() + 'Logging in…' : submitLabel) + '</button></div></form></div></div>';
        var focus = els.login.querySelector('[data-autofocus]');
        if (focus && !state.loggingIn) { setTimeout(function () { focus.focus(); }, 20); }
    }
    function openLogin(tab) {
        state.loginOpen = true; state.loginError = ''; state.authMenuOpen = false; state.loginValues = {};
        state.loginTab = S.autoLogin ? (tab || 'credentials') : 'token';
        renderAuth(); renderLogin();
    }

    function findToken(payload) {
        var paths = ['token', 'access_token', 'accessToken', 'plainTextToken', 'data.token', 'data.access_token', 'data.accessToken', 'data.plainTextToken', 'token.plainTextToken'];
        if (S.autoLogin && S.autoLogin.tokenPath) { paths.unshift(S.autoLogin.tokenPath); }
        for (var i = 0; i < paths.length; i++) {
            var value = paths[i].split('.').reduce(function (v, k) { return v && typeof v === 'object' ? v[k] : undefined; }, payload);
            if (typeof value === 'string' && value !== '') { return value; }
        }
        return null;
    }
    function loginErrorMessage(payload, status) {
        if (payload && payload.errors && typeof payload.errors === 'object') {
            return Object.keys(payload.errors).map(function (k) { return [].concat(payload.errors[k]).join('\n'); }).join('\n');
        }
        return (payload && payload.message) || ('Login failed (HTTP ' + status + ').');
    }
    function setToken(token, message) {
        state.token = token || null;
        writeToken(state.token);
        state.authMenuOpen = false;
        renderAuth(); renderInfo(); renderOps();
        if (message) { toast(message); }
    }
    function submitLogin() {
        if (!S.autoLogin || state.loginTab === 'token') {
            var raw = state.loginValues.token != null ? state.loginValues.token : (state.token || '');
            var pasted = String(raw).trim().replace(/^Bearer\s+/i, '');
            if (!pasted) { state.loginError = 'Enter a token first.'; renderLogin(); return; }
            state.loginOpen = false; state.loginValues = {}; renderLogin();
            setToken(pasted, 'Token saved · sent with protected requests');
            return;
        }
        var body = {};
        Object.keys(S.autoLogin.fields || {}).forEach(function (name) {
            body[name] = state.loginValues[name] != null ? state.loginValues[name] : ((S.autoLogin.prefill || {})[name] || '');
        });
        state.loggingIn = true; state.loginError = ''; renderLogin();
        fetch(S.autoLogin.url, { method: S.autoLogin.method || 'POST', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: JSON.stringify(body) })
            .then(function (response) {
                return response.json().catch(function () { return null; }).then(function (payload) {
                    if (!response.ok) { throw new Error(loginErrorMessage(payload, response.status)); }
                    var token = findToken(payload);
                    if (!token) { throw new Error('Logged in, but no token was found in the response. Set swagger.auto_login.token_path.'); }
                    state.loggingIn = false; state.loginOpen = false; state.loginValues = {}; state.results = {};
                    renderLogin();
                    setToken(token, 'Logged in · token applied');
                });
            })
            .catch(function (error) { state.loggingIn = false; state.loginError = error.message; renderLogin(); });
    }

    /* ---------- info, nav, states ---------- */

    function renderVersions() {
        els.version.innerHTML = versionNames.map(function (n) { return '<option value="' + esc(n) + '"' + (n === state.version ? ' selected' : '') + '>' + esc(n) + '</option>'; }).join('');
        els.version.disabled = versionNames.length < 2;
    }

    function renderInfo() {
        var spec = state.spec || {}, info = spec.info || {}, model = state.model;
        var url = versionUrl(state.version);
        var servers = model ? model.servers : [];
        var serverSelect = servers.length ? '<label class="g4t-select-wrap"><span class="g4t-sr">Server</span><select class="g4t-select" data-bind="server">' +
            servers.map(function (s) { return '<option value="' + esc(s.url) + '"' + (s.url === state.server ? ' selected' : '') + '>' + esc(s.url) + (s.label ? ' — ' + esc(s.label) : '') + '</option>'; }).join('') +
            '</select><svg class="g4t-select-chev" aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m6 9 6 6 6-6"></path></svg></label>' : '';
        var total = model ? model.ops.length : 0;
        els.info.innerHTML =
            '<div class="g4t-badges">' + (info.version ? '<span class="g4t-badge">' + esc(info.version) + '</span>' : '') + (spec.openapi ? '<span class="g4t-badge">OAS ' + esc(String(spec.openapi).split('.').slice(0, 2).join('.')) + '</span>' : '') +
            '<a class="g4t-mono" style="font-size:12px" href="' + esc(url) + '" target="_blank" rel="noopener">' + esc(url.replace(location.origin, '')) + '</a></div>' +
            '<h1>' + esc(info.title || document.title) + '</h1>' +
            (info.description ? '<p class="g4t-info-desc">' + esc(info.description) + '</p>' : '') +
            (model ? '<div class="g4t-info-meta">' + (serverSelect ? '<span><span class="g4t-muted">Server</span>' + serverSelect + '</span>' : '') +
                '<span><span class="g4t-muted" style="display:inline-flex">' + ICON.lock.replace('width="14" height="14"', 'width="13" height="13"') + '</span>' + esc(schemeLabel()) + ' · ' + securedCount() + ' of ' + total + ' operations protected</span>' +
                '<button type="button" class="g4t-btn" style="height:28px" data-action="open-token">' + ICON.key + (state.token ? 'Change token' : 'Authorize') + '</button></div>' : '');
    }

    function filteredTags() {
        if (!state.model) { return []; }
        var q = state.filter.trim().toLowerCase();
        return state.model.tags.map(function (t) {
            var ops = t.ops.filter(function (o) { return !q || (o.method + ' ' + o.path + ' ' + o.summary + ' ' + t.name).toLowerCase().indexOf(q) !== -1; });
            return { name: t.name, desc: t.desc, anchor: t.anchor, ops: ops };
        }).filter(function (t) { return t.ops.length; });
    }

    function renderNav() {
        var tags = filteredTags();
        if (state.loading) { els.nav.innerHTML = '<div class="g4t-nav-empty">Loading…</div>'; return; }
        if (state.error) { els.nav.innerHTML = '<div class="g4t-nav-empty">Definition failed to load.</div>'; return; }
        if (!state.model.ops.length) { els.nav.innerHTML = '<div class="g4t-nav-empty">No operations in ' + esc(state.version) + '.</div>'; return; }
        if (!tags.length) { els.nav.innerHTML = '<div class="g4t-nav-empty">No matches.</div>'; return; }
        els.nav.innerHTML = tags.map(function (t) {
            return '<div class="g4t-nav-group"><button type="button" class="g4t-nav-tag" data-action="goto-tag" data-anchor="' + esc(t.anchor) + '"><span>' + esc(t.name) + '</span><span>' + t.ops.length + '</span></button>' +
                t.ops.map(function (o) {
                    var m = o.method.toLowerCase();
                    return '<button type="button" class="g4t-nav-op" data-action="goto" data-op="' + esc(o.id) + '"' + (state.active === o.id ? ' aria-current="true"' : '') + '><span class="g4t-nav-method m-' + esc(m) + '">' + (o.method === 'DELETE' ? 'DEL' : esc(o.method)) + '</span><span class="g4t-nav-summary">' + esc(o.summary || o.path) + '</span></button>';
                }).join('') + '</div>';
        }).join('');
    }

    /* ---------- operations ---------- */

    function opById(id) { return state.model ? state.model.ops.filter(function (o) { return o.id === id; })[0] : null; }
    function currentCtype(op) { return state.ctype[op.id] || op.ctypes[0] || null; }
    function bodySchema(op) { var c = currentCtype(op); return c ? op.body.content[c].schema || {} : {}; }
    function isMultipart(op) { return /multipart|form-urlencoded/.test(currentCtype(op) || ''); }
    function paramValue(op, p) {
        var saved = (state.inputs[op.id] || {})[p.name];
        if (saved != null) { return saved; }
        var s = resolve(p.schema || {}).schema;
        if (p.example !== undefined) { return String(p.example); }
        if (s.example !== undefined) { return String(s.example); }
        if (s.default !== undefined) { return String(s.default); }
        if (p.in === 'path') { return schemaType(s) === 'integer' || schemaType(s) === 'number' ? '1' : ''; }
        return '';
    }
    function bodyText(op) {
        if (state.bodies[op.id] != null) { return state.bodies[op.id]; }
        var value = sample(bodySchema(op));
        return pretty(value);
    }
    /** multipart: one input per top-level property (files get a file input). */
    function formFields(op) {
        var s = resolve(bodySchema(op)).schema, required = s.required || [];
        return Object.keys(s.properties || {}).map(function (name) {
            var prop = s.properties[name], ps = resolve(prop).schema, value = (state.forms[op.id] || {})[name];
            if (value == null) {
                var ex = sample(prop);
                value = Array.isArray(ex) ? ex.filter(function (v) { return typeof v !== 'object'; }).join(',') : (ex !== null && typeof ex === 'object' ? JSON.stringify(ex) : (typeof ex === 'boolean' ? (ex ? '1' : '0') : String(ex)));
            }
            return { name: name, binary: isBinary(prop), multiple: schemaType(ps) === 'array', enumValues: ps.enum || null, value: value, req: required.indexOf(name) !== -1 };
        });
    }

    function buildRequest(op) {
        var server = (state.server || location.origin).replace(/\/+$/, '');
        var path = op.path, query = [], headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var missing = [];
        op.params.forEach(function (p) {
            var value = paramValue(op, p);
            if (p.in === 'path') {
                if (value === '') { missing.push(p.name); }
                path = path.replace('{' + p.name + '}', encodeURIComponent(value));
            } else if (p.in === 'query' && value !== '') {
                var isArray = schemaType(resolve(p.schema || {}).schema) === 'array' || /\[\]$/.test(p.name);
                (isArray ? value.split(',') : [value]).forEach(function (v) { query.push(encodeURIComponent(p.name) + '=' + encodeURIComponent(v.trim())); });
            } else if (p.in === 'header' && value !== '') {
                headers[p.name] = value;
            }
        });
        if (missing.length) { return { error: 'Fill in the path parameter' + (missing.length > 1 ? 's ' : ' ') + missing.join(', ') }; }
        if (op.secured && state.token) { headers['Authorization'] = 'Bearer ' + state.token; }
        var req = { method: op.method, url: server + path + (query.length ? '?' + query.join('&') : ''), headers: headers, body: undefined, curlBody: [] };
        if (op.body && ['GET', 'HEAD'].indexOf(op.method) === -1) {
            if (isMultipart(op)) {
                var form = new FormData();
                formFields(op).forEach(function (f) {
                    if (f.binary) {
                        var files = (state.files[op.id] || {})[f.name] || [];
                        Array.prototype.forEach.call(files, function (file) { form.append(f.name, file); req.curlBody.push("-F '" + f.name + '=@' + file.name + "'"); });
                    } else if (f.value !== '') {
                        (f.multiple ? f.value.split(',') : [f.value]).forEach(function (v) {
                            v = f.multiple ? v.trim() : v;
                            form.append(f.name, v);
                            req.curlBody.push("-F '" + f.name + '=' + v.replace(/'/g, "'\\''") + "'");
                        });
                    }
                });
                req.body = form;
            } else {
                var text = bodyText(op);
                try { text = JSON.stringify(JSON.parse(text)); } catch (e) { return { error: 'Request body isn’t valid JSON' }; }
                headers['Content-Type'] = currentCtype(op) || 'application/json';
                req.body = text;
                req.curlBody.push("-H 'Content-Type: " + headers['Content-Type'] + "'", "-d '" + text.replace(/'/g, "'\\''") + "'");
            }
        }
        return req;
    }
    function curl(op) {
        var req = buildRequest(op);
        if (req.error) {
            req = { method: op.method, url: (state.server || location.origin) + op.path, headers: { 'Accept': 'application/json' }, curlBody: [] };
        }
        var lines = ["curl -X " + req.method + " '" + req.url + "'"];
        Object.keys(req.headers).forEach(function (h) { if (h !== 'X-Requested-With' && h !== 'Content-Type') { lines.push("  -H '" + h + ': ' + req.headers[h] + "'"); } });
        req.curlBody.forEach(function (l) { lines.push('  ' + l); });
        return lines.join(' \\\n');
    }

    function execute(op) {
        var req = buildRequest(op);
        if (req.error) { toast(req.error); return; }
        state.busy[op.id] = true; renderOp(op.id);
        var started = performance.now();
        fetch(req.url, { method: req.method, headers: req.headers, body: req.body })
            .then(function (response) {
                return response.text().then(function (text) {
                    var json; try { json = text ? JSON.parse(text) : null; } catch (e) { json = undefined; }
                    state.results[op.id] = { code: response.status, text: statusText(response.status, response.statusText), ms: Math.round(performance.now() - started), json: json, raw: text, ctype: (response.headers.get('content-type') || '').split(';')[0], unauth: response.status === 401 && !state.token };
                });
            })
            .catch(function (error) {
                state.results[op.id] = { code: 0, text: 'Network error', ms: Math.round(performance.now() - started), json: undefined, raw: error.message + '\n\nThe request did not reach the server. Check the server URL above and CORS.', ctype: '' };
            })
            .then(function () { state.busy[op.id] = false; renderOp(op.id); });
    }

    function rowsHtml(rows) {
        return '<div class="g4t-table">' + rows.map(function (r) {
            return '<div class="g4t-row"><div class="g4t-row-name' + (r.level ? ' is-nested' : '') + '"' + (r.level > 1 ? ' style="margin-inline-start:' + ((r.level - 1) * 12) + 'px"' : '') + '><div class="g4t-field"><span class="g4t-field-name">' + esc(r.name) + '</span>' + (r.req ? '<span class="g4t-req">required</span>' : '') + '</div><div class="g4t-type">' + esc(r.type) + (r.in ? ' · ' + esc(r.in) : '') + '</div></div><div class="g4t-note">' + esc(r.note) + '</div></div>';
        }).join('') + '</div>';
    }

    function tryHtml(op) {
        var server = state.server || location.origin, html = '';
        html += '<div class="g4t-try"><div class="g4t-try-title"><h3 style="margin:0;font-size:13px;font-weight:600">Try it out</h3><span>→ ' + esc(server) + '</span></div>';
        if (op.secured && !state.token) {
            html += '<div class="g4t-hint">' + ICON.lock + '<span>Needs a token. Requests will return 401 until you log in.</span><button type="button" data-action="open-login">Log in</button></div>';
        }
        var inputs = op.params.filter(function (p) { return ['path', 'query', 'header'].indexOf(p.in) !== -1; });
        if (inputs.length) {
            html += '<div class="g4t-inputs">' + inputs.map(function (p) {
                var s = resolve(p.schema || {}).schema, value = paramValue(op, p);
                var control = s.enum
                    ? '<select data-bind="param" data-op="' + esc(op.id) + '" data-name="' + esc(p.name) + '"><option value=""></option>' + s.enum.map(function (v) { return '<option' + (String(v) === value ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') + '</select>'
                    : '<input data-bind="param" data-op="' + esc(op.id) + '" data-name="' + esc(p.name) + '" value="' + esc(value) + '"' + (p.in === 'path' ? ' required' : '') + ' placeholder="' + esc(typeLabel(p.schema || {})) + '">';
                return '<label class="g4t-input"><span>' + esc(p.name) + ' <em>' + esc(p.in) + (p.required ? ' · required' : '') + '</em></span>' + control + '</label>';
            }).join('') + '</div>';
        }
        if (op.body) {
            if (isMultipart(op)) {
                html += '<div class="g4t-block"><span style="font-size:12px;color:var(--text-2)">Request body <span class="g4t-mono g4t-muted">' + esc(currentCtype(op)) + '</span></span><div class="g4t-inputs">' + formFields(op).map(function (f) {
                    var control;
                    if (f.binary) {
                        control = '<input type="file" data-bind="file" data-op="' + esc(op.id) + '" data-name="' + esc(f.name) + '"' + (f.multiple ? ' multiple' : '') + '>';
                    } else if (f.enumValues) {
                        control = '<select data-bind="form" data-op="' + esc(op.id) + '" data-name="' + esc(f.name) + '">' + (f.req ? '' : '<option value=""></option>') + f.enumValues.map(function (v) { return '<option' + (String(v) === f.value ? ' selected' : '') + '>' + esc(v) + '</option>'; }).join('') + '</select>';
                    } else {
                        control = '<input data-bind="form" data-op="' + esc(op.id) + '" data-name="' + esc(f.name) + '" value="' + esc(f.value) + '"' + (f.multiple ? ' placeholder="comma,separated"' : '') + '>';
                    }
                    return '<label class="g4t-input"><span>' + esc(f.name) + (f.req ? ' <em>required</em>' : '') + '</span>' + control + '</label>';
                }).join('') + '</div></div>';
            } else {
                var text = bodyText(op);
                html += '<label class="g4t-block"><span style="font-size:12px;color:var(--text-2)">Request body <span class="g4t-mono g4t-muted">' + esc(currentCtype(op)) + '</span></span><textarea class="g4t-textarea" data-bind="body" data-op="' + esc(op.id) + '" rows="' + Math.min(14, text.split('\n').length + 1) + '" spellcheck="false">' + esc(text) + '</textarea></label>';
            }
        }
        var busy = !!state.busy[op.id], res = state.results[op.id];
        html += '<div class="g4t-actions"><button type="button" class="g4t-btn g4t-btn-primary" data-action="execute" data-op="' + esc(op.id) + '"' + (busy ? ' disabled' : '') + '>' + (busy ? spinner() + 'Sending…' : 'Execute') + '</button>' +
            (res ? '<button type="button" class="g4t-btn g4t-btn-ghost" data-action="clear-result" data-op="' + esc(op.id) + '">Clear</button>' : '') + '</div>';
        if (res) {
            var hasJson = res.json !== undefined && res.json !== null;
            var bodyHtml = res.code === 204 || res.raw === '' ? 'No content' : hasJson ? highlight(pretty(res.json)) : esc(res.raw.length > 20000 ? res.raw.slice(0, 20000) + '\n…' : res.raw);
            html += '<div class="g4t-result" aria-live="polite"><div class="g4t-actions"><span style="font-size:13px;font-weight:600">Response</span><span class="g4t-status ' + statusClass(res.code) + '">' + (res.code || '—') + ' ' + esc(res.text) + '</span><span class="g4t-muted" style="font-size:12px">' + res.ms + ' ms' + (res.ctype ? ' · ' + esc(res.ctype) : '') + '</span></div>' +
                (res.unauth ? '<div style="font-size:13px;color:var(--text-2)">The request went out without a token. <button type="button" class="g4t-link-btn" data-action="open-login">Log in</button> and execute again.</div>' : '') +
                '<div class="g4t-code-wrap"><pre class="g4t-code">' + bodyHtml + '</pre>' + (res.raw ? '<button type="button" class="g4t-copy" aria-label="Copy response" data-action="copy-result" data-op="' + esc(op.id) + '">' + ICON.copy + '</button>' : '') + '</div>' +
                '<div style="display:grid;gap:6px;min-width:0"><div style="display:flex;align-items:center;justify-content:space-between;gap:8px"><span class="g4t-muted" style="font-size:12px">Request as cURL</span><button type="button" class="g4t-link-btn" style="font-size:12px;font-weight:500" data-action="copy-curl" data-op="' + esc(op.id) + '">Copy</button></div><pre class="g4t-code is-muted">' + esc(curl(op)) + '</pre></div></div>';
        }
        return html + '</div>';
    }

    function responsesHtml(op) {
        if (!op.responses.length) { return ''; }
        var code = state.respTab[op.id] != null ? state.respTab[op.id] : op.responses[0].code;
        var resp = op.responses.filter(function (r) { return r.code === code; })[0] || op.responses[0];
        var exIndex = Math.min(state.exTab[op.id] || 0, resp.examples.length - 1), ex = resp.examples[exIndex];
        var view = resp.schema ? (state.respView[op.id] || 'example') : 'example';
        var html = '<div class="g4t-block" style="gap:10px"><div class="g4t-block-head"><h3>Responses</h3>' +
            (resp.schema ? '<div class="g4t-seg g4t-view-toggle" role="tablist" aria-label="Response view" style="grid-template-columns:repeat(2,auto)"><button type="button" role="tab" aria-selected="' + (view === 'example') + '" data-action="resp-view" data-op="' + esc(op.id) + '" data-value="example">Example</button><button type="button" role="tab" aria-selected="' + (view === 'schema') + '" data-action="resp-view" data-op="' + esc(op.id) + '" data-value="schema">Schema</button></div>' : '') +
            '</div><div class="g4t-status-tabs" role="tablist" aria-label="Status codes">' +
            op.responses.map(function (r) {
                return '<button type="button" role="tab" class="g4t-status-tab ' + statusClass(typeof r.code === 'number' ? r.code : 0) + '" aria-selected="' + (r.code === resp.code) + '" data-action="resp-tab" data-op="' + esc(op.id) + '" data-value="' + esc(r.code) + '"><span class="g4t-dot" aria-hidden="true"></span><b>' + esc(r.code) + '</b><span>' + esc(statusText(r.code, r.desc)) + '</span></button>';
            }).join('') + '</div>';
        if (view === 'schema') {
            var r = resolve(resp.schema), rows = fields(resp.schema);
            var head = '<div class="g4t-type" style="font-size:12.5px">' + esc(typeLabel(resp.schema)) + (r.name ? '' : '') + '</div>';
            if (!rows.length && schemaType(r.schema) === 'array' && r.schema.items) { rows = fields(r.schema.items); }
            return html + head + (rows.length ? rowsHtml(rows) : '<pre class="g4t-code is-muted">' + esc(pretty(r.schema)) + '</pre>') + '</div>';
        }
        if (resp.examples.length > 1) {
            html += '<div class="g4t-ex-tabs" role="tablist" aria-label="Examples">' + resp.examples.map(function (e, i) {
                return '<button type="button" role="tab" aria-selected="' + (i === exIndex) + '" data-action="ex-tab" data-op="' + esc(op.id) + '" data-value="' + i + '">' + esc(e.name) + '</button>';
            }).join('') + '</div>';
        }
        var body = ex.has ? highlight(pretty(ex.value)) : esc(resp.code === 204 ? '204 — no response body' : (resp.desc || 'No example'));
        return html + '<div class="g4t-code-wrap"><pre class="g4t-code">' + body + '</pre>' + (ex.has ? '<button type="button" class="g4t-copy" aria-label="Copy example" data-action="copy-example" data-op="' + esc(op.id) + '">' + ICON.copy + '</button>' : '') + '</div></div>';
    }

    function opHtml(op) {
        var open = !!state.expanded[op.id], trying = !!state.trying[op.id], m = op.method.toLowerCase();
        var html = '<article id="op-' + esc(op.id) + '" class="g4t-op' + (open ? ' is-open' : '') + (op.deprecated ? ' g4t-deprecated' : '') + '">' +
            '<button type="button" class="g4t-op-head" data-action="toggle-op" data-op="' + esc(op.id) + '" aria-expanded="' + open + '">' +
            '<span class="g4t-method m-' + esc(m) + '">' + esc(op.method) + '</span><span class="g4t-path">' + esc(op.path) + '</span><span class="g4t-op-summary">' + esc(op.summary) + '</span>' +
            '<span class="g4t-op-tail">' + (op.secured ? '<span class="g4t-lock' + (state.token ? ' is-ready' : '') + '" title="' + (state.token ? 'Token will be sent' : 'Requires a token') + '">' + ICON.lock + '</span>' : '') + ICON.chev + '</span></button>';
        if (!open) { return html + '</article>'; }

        html += '<div class="g4t-op-body"><div class="g4t-op-intro"><p class="g4t-op-desc">' + esc(op.desc || op.summary || 'No description.') + '</p><div style="display:flex;gap:8px;flex:none">' +
            '<button type="button" class="g4t-btn" data-action="copy-curl" data-op="' + esc(op.id) + '">' + ICON.term + 'Copy as cURL</button>' +
            '<button type="button" class="g4t-try-toggle" data-action="toggle-try" data-op="' + esc(op.id) + '" aria-pressed="' + trying + '">' + (trying ? 'Cancel' : 'Try it out') + '</button></div></div>';
        if (op.params.length) {
            html += '<div class="g4t-block"><h3>Parameters</h3>' + rowsHtml(op.params.map(function (p) {
                return { name: p.name, in: p.in, type: typeLabel(p.schema || {}), req: !!p.required, note: p.description || noteFor(p.schema || {}), level: 0 };
            })) + '</div>';
        }
        if (op.body) {
            var ctype = currentCtype(op);
            html += '<div class="g4t-block"><div class="g4t-block-head"><h3>Request body</h3>' + (op.ctypes.length ? '<div class="g4t-ctype" role="tablist" aria-label="Content type">' + op.ctypes.map(function (c) {
                return '<button type="button" role="tab" aria-selected="' + (c === ctype) + '" data-action="ctype" data-op="' + esc(op.id) + '" data-value="' + esc(c) + '">' + esc(c) + '</button>';
            }).join('') + '</div>' : '') + '</div>';
            var rows = fields(bodySchema(op));
            html += (rows.length ? rowsHtml(rows) : '<div class="g4t-note">' + esc(typeLabel(bodySchema(op))) + '</div>') + '</div>';
        }
        if (trying) { html += tryHtml(op); }
        html += responsesHtml(op);
        return html + '</div></article>';
    }

    function renderOp(id) {
        var op = opById(id), el = document.getElementById('op-' + id);
        if (!op || !el) { return; }
        var holder = document.createElement('div');
        holder.innerHTML = opHtml(op);
        el.replaceWith(holder.firstChild);
    }

    function renderOps() {
        if (state.loading) {
            els.ops.innerHTML = '<div class="g4t-loading">' + spinner() + 'Loading the API definition…</div>';
            return;
        }
        if (state.error) {
            var e = state.error;
            els.ops.innerHTML = '<div class="g4t-state g4t-state-error" role="alert"><div class="g4t-state-icon">' + ICON.warn + '</div><div style="display:grid;gap:6px"><h2>Couldn’t load the API definition</h2><p>The request for the <strong>' + esc(state.version) + '</strong> spec failed' + (e.status ? ' with HTTP ' + esc(e.status) : '') + '. This usually means the generator hit an exception while reading a controller or FormRequest.</p></div>' +
                '<pre class="g4t-code is-muted" style="white-space:pre-wrap;word-break:break-word">GET ' + esc(e.url) + '\n' + esc((e.status ? e.status + ' ' : '') + (e.statusText || '')) + (e.message ? '\n' + esc(e.message) : '') + '</pre>' +
                '<div class="g4t-actions"><button type="button" class="g4t-btn g4t-btn-primary" data-action="retry">Retry</button><a class="g4t-btn" href="' + esc(e.url) + '" target="_blank" rel="noopener">Open raw JSON</a></div></div>';
            return;
        }
        if (!state.model.ops.length) {
            els.ops.innerHTML = '<div class="g4t-state g4t-state-empty"><div class="g4t-state-icon">{ }</div><h2>No operations in ' + esc(state.version) + '</h2><p>The definition loaded, but no routes match this version. Check the version prefixes in <code class="g4t-mono" style="font-size:12.5px;padding:1px 5px;border-radius:4px;background:var(--surface-2)">config/swagger.php</code> or register routes under it.</p>' +
                (state.version !== 'all' && versionNames.indexOf('all') !== -1 ? '<button type="button" class="g4t-btn" data-action="show-all" style="margin-top:4px;height:34px;padding-inline:14px">Show all versions</button>' : '') + '</div>';
            return;
        }
        var tags = filteredTags();
        if (!tags.length) {
            els.ops.innerHTML = '<div class="g4t-state g4t-state-nomatch"><span style="color:var(--text-2)">No operations match “<strong style="color:var(--text)">' + esc(state.filter) + '</strong>”.</span><button type="button" class="g4t-btn" data-action="clear-filter">Clear filter</button></div>';
            return;
        }
        els.ops.innerHTML = tags.map(function (t) {
            return '<section id="' + esc(t.anchor) + '" class="g4t-section"><div class="g4t-section-head"><h2>' + esc(t.name) + '</h2><span>' + esc(t.desc) + '</span></div><div class="g4t-op-list">' + t.ops.map(opHtml).join('') + '</div></section>';
        }).join('');
    }

    function renderAll() { renderAuth(); renderInfo(); renderNav(); renderOps(); }

    function scrollToId(id) {
        requestAnimationFrame(function () {
            var el = document.getElementById(id);
            if (!el) { return; }
            var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 68, behavior: reduce ? 'auto' : 'smooth' });
        });
    }
    function setDrawer(open) {
        state.drawer = open;
        els.app.classList.toggle('drawer-open', open);
        els.scrim.hidden = !open;
        els.drawerBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    function gotoOp(id, fromHash) {
        state.active = id; state.expanded[id] = true;
        if (state.filter && !filteredTags().some(function (t) { return t.ops.some(function (o) { return o.id === id; }); })) { state.filter = ''; els.filter.value = ''; }
        setDrawer(false);
        renderNav(); renderOps();
        if (!fromHash) { try { history.replaceState(null, '', '#op-' + id); } catch (e) { } }
        scrollToId('op-' + id);
    }

    /* ---------- loading ---------- */

    function load() {
        state.loading = true; state.error = null; renderAll();
        var url = versionUrl(state.version);
        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (response) {
                return response.text().then(function (text) {
                    var json = null; try { json = JSON.parse(text); } catch (e) { }
                    if (!response.ok || !json || typeof json !== 'object') {
                        throw { status: response.status, statusText: statusText(response.status, response.statusText), message: (json && json.message) || (response.ok ? 'The response is not a JSON document.' : ''), url: url };
                    }
                    return json;
                });
            })
            .then(function (spec) {
                state.spec = spec;
                state.model = buildModel(spec);
                var saved = store.get('server', null), servers = state.model.servers;
                state.server = servers.some(function (s) { return s.url === saved; }) ? saved : (servers[0] ? servers[0].url : location.origin);
                state.loading = false;
                renderAll();
                var hash = decodeURIComponent((location.hash || '').replace(/^#/, ''));
                if (hash.indexOf('op-') === 0 && opById(hash.slice(3))) { gotoOp(hash.slice(3), true); }
            })
            .catch(function (error) {
                state.loading = false;
                state.error = error && error.url ? error : { status: 0, statusText: 'Network error', message: String(error && error.message || error), url: url };
                state.spec = null; state.model = null;
                renderAll();
            });
    }

    /* ---------- events ---------- */

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-action]');
        if (!el) { return; }
        var action = el.getAttribute('data-action'), id = el.getAttribute('data-op'), value = el.getAttribute('data-value'), op = id ? opById(id) : null;
        switch (action) {
            case 'toggle-drawer': setDrawer(!state.drawer); break;
            case 'close-drawer': setDrawer(false); break;
            case 'toggle-appear': state.appearOpen = !state.appearOpen; state.authMenuOpen = false; renderAppear(); renderAuth(); break;
            case 'mode': state.mode = value; store.set('mode', value); applyRoot(); renderAppear(); break;
            case 'density': state.density = value; store.set('density', value); applyRoot(); renderAppear(); break;
            case 'theme': state.theme = value; store.set('theme', value); applyRoot(); renderAppear(); break;
            case 'toggle-auth-menu': state.authMenuOpen = !state.authMenuOpen; state.appearOpen = false; renderAuth(); renderAppear(); break;
            case 'copy-token': if (state.token) { copy(state.token, 'Token copied'); } state.authMenuOpen = false; renderAuth(); break;
            case 'logout': setToken(null, 'Logged out'); break;
            case 'open-login': openLogin('credentials'); break;
            case 'open-token': openLogin('token'); break;
            case 'login-tab': state.loginTab = value; state.loginError = ''; renderLogin(); break;
            case 'paste-token':
                if (navigator.clipboard && navigator.clipboard.readText) {
                    navigator.clipboard.readText().then(function (text) {
                        var input = els.login.querySelector('input[name="token"]');
                        state.loginValues.token = String(text || '').trim();
                        if (input) { input.value = state.loginValues.token; input.focus(); }
                    }, function () { toast('Clipboard access was blocked — paste with ⌘V / Ctrl+V'); });
                } else {
                    toast('Paste with ⌘V / Ctrl+V');
                }
                break;
            case 'close-login': state.loginOpen = false; renderLogin(); break;
            case 'close-login-backdrop': if (e.target === el) { state.loginOpen = false; renderLogin(); } break;
            case 'goto': if (op) { gotoOp(op.id); } break;
            case 'goto-tag': setDrawer(false); scrollToId(value || el.getAttribute('data-anchor')); break;
            case 'toggle-op':
                state.expanded[id] = !state.expanded[id]; state.active = id;
                renderOp(id); renderNav();
                if (state.expanded[id]) { try { history.replaceState(null, '', '#op-' + id); } catch (err) { } }
                break;
            case 'toggle-try': state.trying[id] = !state.trying[id]; renderOp(id); break;
            case 'ctype': state.ctype[id] = value; renderOp(id); break;
            case 'execute': if (op) { execute(op); } break;
            case 'clear-result': delete state.results[id]; renderOp(id); break;
            case 'copy-curl': if (op) { copy(curl(op), 'cURL copied'); } break;
            case 'copy-result': if (state.results[id]) { copy(state.results[id].json != null ? pretty(state.results[id].json) : state.results[id].raw, 'Response copied'); } break;
            case 'resp-tab': state.respTab[id] = isNaN(Number(value)) ? value : Number(value); state.exTab[id] = 0; renderOp(id); break;
            case 'ex-tab': state.exTab[id] = Number(value); renderOp(id); break;
            case 'resp-view': state.respView[id] = value; renderOp(id); break;
            case 'copy-example':
                if (op) {
                    var code = state.respTab[id] != null ? state.respTab[id] : op.responses[0].code;
                    var resp = op.responses.filter(function (r) { return r.code === code; })[0] || op.responses[0];
                    var ex = resp.examples[Math.min(state.exTab[id] || 0, resp.examples.length - 1)];
                    copy(pretty(ex.value), 'Example copied');
                }
                break;
            case 'retry': load(); break;
            case 'show-all': state.version = 'all'; store.set('version', 'all'); renderVersions(); load(); break;
            case 'clear-filter': state.filter = ''; els.filter.value = ''; renderNav(); renderOps(); break;
        }
    });

    document.addEventListener('input', function (e) {
        var el = e.target, bind = el.getAttribute && el.getAttribute('data-bind');
        if (el === els.filter) { state.filter = el.value; renderNav(); renderOps(); return; }
        var id = el.getAttribute && el.getAttribute('data-op'), name = el.getAttribute && el.getAttribute('data-name');
        if (bind === 'param') { (state.inputs[id] = state.inputs[id] || {})[name] = el.value; }
        else if (bind === 'form') { (state.forms[id] = state.forms[id] || {})[name] = el.value; }
        else if (bind === 'body') { state.bodies[id] = el.value; }
        else if (bind === 'login') { state.loginValues[el.name] = el.value; }
    });

    document.addEventListener('change', function (e) {
        var el = e.target, bind = el.getAttribute && el.getAttribute('data-bind');
        var id = el.getAttribute && el.getAttribute('data-op'), name = el.getAttribute && el.getAttribute('data-name');
        if (el === els.version) { state.version = el.value; store.set('version', el.value); state.results = {}; load(); }
        else if (bind === 'server') { state.server = el.value; store.set('server', el.value); toast('Server: ' + el.value); Object.keys(state.trying).forEach(function (k) { if (state.trying[k]) { renderOp(k); } }); }
        else if (bind === 'file') { (state.files[id] = state.files[id] || {})[name] = el.files; }
        else if (bind === 'param') { (state.inputs[id] = state.inputs[id] || {})[name] = el.value; }
        else if (bind === 'form') { (state.forms[id] = state.forms[id] || {})[name] = el.value; }
    });

    document.addEventListener('submit', function (e) {
        if (e.target && e.target.id === 'g4t-login-form') { e.preventDefault(); submitLogin(); }
    });

    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        if (e.key === '/' && ['input', 'textarea', 'select'].indexOf(tag) === -1 && !e.target.isContentEditable) {
            e.preventDefault(); setDrawer(window.innerWidth < 900 ? true : state.drawer); els.filter.focus();
        }
        if (e.key === 'Escape') {
            var changed = state.appearOpen || state.authMenuOpen || state.loginOpen || state.drawer;
            state.appearOpen = false; state.authMenuOpen = false; state.loginOpen = false;
            if (changed) { setDrawer(false); renderAppear(); renderAuth(); renderLogin(); }
            if (e.target === els.filter && els.filter.value) { state.filter = ''; els.filter.value = ''; renderNav(); renderOps(); }
        }
    });

    document.addEventListener('mousedown', function (e) {
        var t = e.target;
        if (state.appearOpen && !(t.closest && t.closest('[data-g4t-pop="appear"]'))) { state.appearOpen = false; renderAppear(); }
        if (state.authMenuOpen && !(t.closest && t.closest('[data-g4t-pop="auth"]'))) { state.authMenuOpen = false; renderAuth(); }
    });

    if (window.matchMedia) {
        var mq = window.matchMedia('(prefers-color-scheme: dark)');
        var onScheme = function () { if (state.mode === 'system') { applyRoot(); renderAppear(); } };
        mq.addEventListener ? mq.addEventListener('change', onScheme) : mq.addListener(onScheme);
    }
    window.addEventListener('hashchange', function () {
        var hash = decodeURIComponent((location.hash || '').replace(/^#/, ''));
        if (hash.indexOf('op-') === 0 && opById(hash.slice(3))) { gotoOp(hash.slice(3), true); }
    });
    window.addEventListener('storage', function (e) {
        if (e.key === tokenKey) { state.token = e.newValue; renderAuth(); renderInfo(); renderOps(); }
    });

    applyRoot();
    renderVersions();
    renderAppear();
    load();
})();
</script>
@endverbatim
