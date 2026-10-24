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
        clock: '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg>',
        reset: '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"></path><path d="M3 3v5h5"></path></svg>',
        download: '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7 10l5 5 5-5M5 20h14"></path></svg>',
        vars: '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 4c-2 0-3 1-3 3v2c0 1.5-1 2.5-2 3 1 .5 2 1.5 2 3v2c0 2 1 3 3 3M16 4c2 0 3 1 3 3v2c0 1.5 1 2.5 2 3-1 .5-2 1.5-2 3v2c0 2-1 3-3 3"></path></svg>',
        upload: '<svg aria-hidden="true" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V4M7 9l5-5 5 5M5 20h14"></path></svg>',
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
        bodies: {}, inputs: {}, forms: {}, files: {}, busy: {}, results: {},
        codeLang: store.get('codeLang', 'curl'), codeOpen: {}, historyOpen: {},
        envs: [], envId: null, envsOpen: false, envEdit: null, downloadOpen: false,
        creds: {}, credDraft: {}
    };

    var $ = function (id) { return document.getElementById(id); };
    var els = { app: document.querySelector('.g4t-app'), version: $('g4t-version'), auth: $('g4t-auth'), appear: $('g4t-appear'), appearBtn: $('g4t-appear-btn'), filter: $('g4t-filter'), nav: $('g4t-nav'), info: $('g4t-info'), ops: $('g4t-ops'), login: $('g4t-login'), toast: $('g4t-toast'), envBtn: $('g4t-env-btn'), envs: $('g4t-envs'), download: $('g4t-download'), downloadBtn: $('g4t-download-btn'), scrim: document.querySelector('.g4t-scrim'), drawerBtn: document.querySelector('.g4t-drawer-btn') };

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
        var globalSecurity = Array.isArray(spec.security) ? spec.security : [];
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
                    security: Array.isArray(o.security) ? o.security : globalSecurity,
                    params: params, body: body && body.content ? body : null, responses: buildResponses(o.responses || {})
                };
                op.secured = op.security.some(function (requirement) { return requirement && Object.keys(requirement).length > 0; });
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

    /**
     * Security schemes of the document as [name, scheme] pairs. Without any, a bearer scheme is
     * assumed so a pasted token still works.
     */
    function schemeEntries() {
        var schemes = state.model ? state.model.schemes : {};
        var names = Object.keys(schemes || {});
        if (!names.length) { return [['bearerAuth', { type: 'http', scheme: 'bearer' }]]; }
        return names.map(function (name) { return [name, schemes[name] || {}]; });
    }
    function schemeOf(name) { var found = schemeEntries().filter(function (e) { return e[0] === name; })[0]; return found ? found[1] : null; }
    /** bearer | basic | apiKey | unsupported (cookie API keys and other HTTP schemes cannot be set from the page). */
    function schemeKind(scheme) {
        scheme = scheme || {};
        if (scheme.type === 'http' && /^basic$/i.test(scheme.scheme || '')) { return 'basic'; }
        if (scheme.type === 'http' && /^bearer$/i.test(scheme.scheme || '')) { return 'bearer'; }
        if (scheme.type === 'oauth2' || scheme.type === 'openIdConnect') { return 'bearer'; }
        if (scheme.type === 'apiKey' && scheme.in !== 'cookie' && scheme.name) { return 'apiKey'; }
        return 'unsupported';
    }
    function schemeDescription(scheme) {
        switch (schemeKind(scheme)) {
            case 'bearer': return (scheme.type === 'http' ? 'Bearer token' : 'Access token') + ' · Authorization header';
            case 'basic': return 'Username and password · Authorization: Basic';
            case 'apiKey': return 'API key · ' + (scheme.in === 'query' ? 'query ?' + scheme.name + '=' : 'header ' + scheme.name);
            default: return scheme.type === 'apiKey' ? 'API key in a cookie' : (scheme.type || 'unknown') + (scheme.scheme ? ' ' + scheme.scheme : '');
        }
    }
    /** The bearer scheme that auto_login and the shared token fill. */
    function primaryBearer() {
        var preferred = S.autoLogin && S.autoLogin.scheme;
        if (preferred && schemeKind(schemeOf(preferred)) === 'bearer') { return preferred; }
        var first = schemeEntries().filter(function (e) { return schemeKind(e[1]) === 'bearer'; })[0];
        return first ? first[0] : (preferred || 'bearerAuth');
    }
    /** Stored credential of a scheme: { value } or { username, password }; null when empty. */
    function credOf(name) {
        if (name === primaryBearer()) { return state.token ? { value: state.token } : null; }
        var cred = state.creds[name];
        if (!cred) { return null; }
        if (cred.username || cred.password) { return cred; }
        return cred.value ? cred : null;
    }
    function setCred(name, cred) {
        if (name === primaryBearer()) { state.token = cred && cred.value ? cred.value : null; writeToken(state.token); return; }
        if (cred) { state.creds[name] = cred; } else { delete state.creds[name]; }
        saveJson('creds', state.creds);
    }
    function hasAnyCred() { return schemeEntries().some(function (e) { return credOf(e[0]); }); }
    /** First security requirement of the operation whose schemes all have credentials ({} = optional). */
    function satisfiedRequirement(op) {
        var list = op.security || [];
        for (var i = 0; i < list.length; i++) {
            var names = Object.keys(list[i] || {});
            if (names.every(function (n) { return credOf(n) && schemeKind(schemeOf(n)) !== 'unsupported'; })) { return list[i] || {}; }
        }
        return null;
    }
    function opAuthReady(op) { return !op.secured || satisfiedRequirement(op) !== null; }
    function requirementLabel(op) {
        var first = (op.security || []).filter(function (r) { return r && Object.keys(r).length; })[0] || {};
        var labels = Object.keys(first).map(function (n) {
            var scheme = schemeOf(n) || {}, k = schemeKind(scheme);
            if (k === 'apiKey') { return 'an API key (' + n + ')'; }
            if (k === 'basic') { return 'a username and password'; }
            if (k === 'unsupported') { return scheme.type === 'apiKey' ? 'a "' + scheme.name + '" cookie the page cannot set' : 'a ' + (scheme.scheme || scheme.type || n) + ' credential the page cannot send'; }
            return 'a token';
        });
        return labels.join(' and ') || 'a token';
    }
    function base64Utf8(text) { return btoa(unescape(encodeURIComponent(text))); }
    /** Puts the operation's credentials where each scheme expects them (header or query). */
    function applyAuth(op, headers, query) {
        if (!op.secured) { return; }
        var requirement = satisfiedRequirement(op);
        if (!requirement) { return; }
        Object.keys(requirement).forEach(function (name) {
            var scheme = schemeOf(name) || {}, cred = credOf(name), kind = schemeKind(scheme);
            if (kind === 'bearer') { headers['Authorization'] = 'Bearer ' + subst(cred.value); }
            else if (kind === 'basic') { headers['Authorization'] = 'Basic ' + base64Utf8(subst(cred.username || '') + ':' + subst(cred.password || '')); }
            else if (kind === 'apiKey' && scheme.in === 'query') { query.push(encodeURIComponent(scheme.name) + '=' + encodeURIComponent(subst(cred.value))); }
            else if (kind === 'apiKey') { headers[scheme.name] = subst(cred.value); }
        });
    }
    /** Commits the Authorize form; false when nothing at all is set afterwards. */
    function saveCredDraft() {
        Object.keys(state.credDraft || {}).forEach(function (name) {
            var draft = state.credDraft[name], kind = schemeKind(schemeOf(name)), current = credOf(name) || {};
            if (kind === 'basic') {
                var username = draft.username != null ? draft.username : (current.username || '');
                var password = draft.password != null ? draft.password : (current.password || '');
                setCred(name, username || password ? { username: username, password: password } : null);
            } else if (kind === 'bearer' || kind === 'apiKey') {
                var value = String(draft.value != null ? draft.value : (current.value || '')).trim();
                if (kind === 'bearer') { value = value.replace(/^Bearer\s+/i, ''); }
                setCred(name, value ? { value: value } : null);
            }
        });
        return hasAnyCred();
    }
    function schemeLabel() {
        var labels = schemeEntries().map(function (e) {
            var kind = schemeKind(e[1]);
            return kind === 'bearer' ? 'Bearer auth' : kind === 'basic' ? 'Basic auth' : kind === 'apiKey' ? 'API key (' + e[1].name + ')' : e[0];
        });
        return labels.filter(function (l, i) { return labels.indexOf(l) === i; }).join(' / ');
    }
    function onlyBearer() { return schemeEntries().every(function (e) { return schemeKind(e[1]) === 'bearer'; }); }
    function previewSecret(text) { text = String(text || ''); return text.length > 20 ? text.slice(0, 10) + '…' + text.slice(-6) : text; }

    function renderAuth() {
        if (!hasAnyCred()) {
            els.auth.innerHTML = '<button type="button" id="g4t-login-toggle" class="g4t-auth-btn g4t-login-toggle" data-action="' + (S.autoLogin ? 'open-login' : 'open-token') + '" aria-label="Log in"><span class="g4t-dot" aria-hidden="true"></span><span class="g4t-auth-text g4t-auth-text-out"><span style="color:var(--text-2)">Not signed in</span><span class="g4t-auth-sep" aria-hidden="true"></span></span><span class="g4t-auth-cta">' + ICON.lock.replace('width="14" height="14"', 'width="13" height="13"') + (S.autoLogin ? 'Log in' : 'Authorize') + '</span></button>';
            return;
        }
        var lines = schemeEntries().map(function (e) {
            var cred = credOf(e[0]);
            if (!cred) { return ''; }
            var shown = schemeKind(e[1]) === 'basic' ? (cred.username || '') + ':••••••' : previewSecret(cred.value);
            return '<div class="g4t-menu-hint" style="font-size:12px">' + esc(e[0]) + ' · ' + esc(schemeDescription(e[1])) + '</div><div class="g4t-token-box">' + esc(shown) + '</div>';
        }).join('');
        els.auth.innerHTML = '<button type="button" id="g4t-login-toggle" class="g4t-auth-btn g4t-login-toggle is-authenticated" data-action="toggle-auth-menu" aria-haspopup="menu" aria-expanded="' + state.authMenuOpen + '"><span class="g4t-dot" aria-hidden="true"></span><span class="g4t-auth-text">Authenticated</span>' + ICON.chevSm + '</button>' +
            (state.authMenuOpen ? '<div class="g4t-menu" role="menu"><div style="display:grid;gap:4px">' + lines + '<div style="font-size:12px;color:var(--text-2)">Sent with ' + securedCount() + ' protected operations</div></div>' +
                '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">' + (state.token ? '<button type="button" role="menuitem" class="g4t-btn" data-action="copy-token">' + ICON.copy + 'Copy token</button>' : '') +
                '<button type="button" role="menuitem" class="g4t-btn" data-action="open-token"' + (state.token ? '' : ' style="grid-column:1 / -1"') + '>' + ICON.key + (onlyBearer() ? 'Change token' : 'Change credentials') + '</button>' +
                '<button type="button" role="menuitem" class="g4t-btn g4t-btn-danger" style="grid-column:1 / -1" data-action="logout">Log out</button></div></div>' : '');
    }

    /** One block per security scheme in the Authorize tab. */
    function credFieldsHtml() {
        return schemeEntries().map(function (entry, index) {
            var name = entry[0], scheme = entry[1], kind = schemeKind(scheme), current = credOf(name) || {}, draft = state.credDraft[name] || {};
            var val = function (field) { return draft[field] != null ? draft[field] : (current[field] || ''); };
            var attrs = function (field) { return ' data-bind="cred" data-scheme="' + esc(name) + '" data-field="' + field + '"' + (index === 0 && field !== 'password' ? ' data-autofocus' : ''); };
            var body;
            if (kind === 'bearer') {
                body = '<div class="g4t-token-field"><input name="token" type="text" class="g4t-mono" autocomplete="off" spellcheck="false" placeholder="1|aBcDeF…" value="' + esc(val('value')) + '"' + attrs('value') + ' aria-label="' + esc(name) + ' token">' +
                    '<button type="button" class="g4t-btn" data-action="paste-token" data-scheme="' + esc(name) + '" title="Paste from clipboard">' + ICON.copy + 'Paste</button></div>';
            } else if (kind === 'basic') {
                body = '<div class="g4t-var-row" style="grid-template-columns:1fr 1fr"><input type="text" autocomplete="username" placeholder="username" value="' + esc(val('username')) + '"' + attrs('username') + ' aria-label="' + esc(name) + ' username">' +
                    '<input type="password" autocomplete="current-password" placeholder="password" value="' + esc(val('password')) + '"' + attrs('password') + ' aria-label="' + esc(name) + ' password"></div>';
            } else if (kind === 'apiKey') {
                body = '<input type="text" class="g4t-mono" autocomplete="off" spellcheck="false" placeholder="key" value="' + esc(val('value')) + '"' + attrs('value') + ' aria-label="' + esc(name) + ' key">';
            } else {
                body = '<p class="g4t-muted" style="margin:0;font-size:12.5px">The browser cannot set this one from the page. Use the code samples instead.</p>';
            }
            return '<div class="g4t-cred"><div class="g4t-cred-head"><span class="g4t-mono">' + esc(name) + '</span><span class="g4t-muted">' + esc(schemeDescription(scheme)) + '</span></div>' + body + '</div>';
        }).join('');
    }

    function renderLogin() {
        if (!state.loginOpen) { els.login.innerHTML = ''; return; }
        var auto = S.autoLogin, tokenTab = !auto || state.loginTab === 'token', fieldsHtml = '', intro, submitLabel;
        if (tokenTab) {
            fieldsHtml = credFieldsHtml();
            intro = 'Stored in this browser and sent with protected requests, the way each scheme expects. A leading “Bearer ” is removed from tokens; leave a field empty to remove it.';
            submitLabel = hasAnyCred() ? 'Save' : 'Authorize';
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
            '<button type="button" role="tab" aria-selected="' + tokenTab + '" data-action="login-tab" data-value="token">' + (onlyBearer() ? 'Bearer token' : 'Keys & tokens') + '</button></div>' : '';
        els.login.innerHTML = '<div class="g4t-modal" data-action="close-login-backdrop"><div id="g4t-login-dialog" class="g4t-login-dialog" role="dialog" aria-modal="true" aria-labelledby="g4t-login-title"><form id="g4t-login-form" novalidate>' +
            '<div style="display:grid;gap:4px"><h2 id="g4t-login-title">' + (tokenTab ? 'Authorize' : 'Log in') + '</h2><p>' + intro + '</p></div>' + tabs + fieldsHtml +
            (state.loginError ? '<p id="g4t-login-error" class="g4t-login-error" role="alert">' + esc(state.loginError) + '</p>' : '') +
            '<div class="g4t-actions"><button type="button" id="g4t-login-cancel" class="g4t-btn" data-action="close-login">Cancel</button><button type="submit" id="g4t-login-submit" class="g4t-btn g4t-btn-primary"' + (state.loggingIn ? ' disabled' : '') + '>' + (state.loggingIn ? spinner() + 'Logging in…' : submitLabel) + '</button></div></form></div></div>';
        var focus = els.login.querySelector('[data-autofocus]');
        if (focus && !state.loggingIn) { setTimeout(function () { focus.focus(); }, 20); }
    }
    function openLogin(tab) {
        state.loginOpen = true; state.loginError = ''; state.authMenuOpen = false; state.loginValues = {}; state.credDraft = {};
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
            if (!saveCredDraft()) { state.loginError = 'Enter a token or key first.'; renderLogin(); return; }
            state.loginOpen = false; state.loginValues = {}; state.credDraft = {}; renderLogin();
            state.authMenuOpen = false; renderAuth(); renderInfo(); renderOps();
            toast('Authorization saved · sent with protected requests');
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

    /* ---------- environments UI ---------- */

    function newId() { return 'env-' + Math.random().toString(36).slice(2, 9); }
    function loadEnvs() {
        var envs = loadJson('envs', null);
        state.envs = Array.isArray(envs) && envs.length ? envs.filter(function (e) { return e && e.id; }).map(function (e) {
            var pairs = function (list) { return Array.isArray(list) ? list.map(function (v) { return { key: String(v.key || ''), value: String(v.value == null ? '' : v.value) }; }) : []; };
            return { id: String(e.id), name: String(e.name || 'Environment'), baseUrl: String(e.baseUrl || ''), vars: pairs(e.vars), headers: pairs(e.headers) };
        }) : [];
        if (!state.envs.length) { state.envs = [{ id: 'default', name: 'Default', baseUrl: '', vars: [], headers: [] }]; }
        var saved = store.get('envId', null);
        state.envId = state.envs.some(function (e) { return e.id === saved; }) ? saved : state.envs[0].id;
    }
    function saveEnvs() { saveJson('envs', state.envs); store.set('envId', state.envId); }
    function editedEnv() { return state.envs.filter(function (e) { return e.id === state.envEdit; })[0] || activeEnv(); }
    function renderEnvButton() {
        var env = activeEnv(), count = (env.vars || []).filter(function (v) { return v.key; }).length;
        els.envBtn.innerHTML = ICON.vars + '<span class="g4t-env-name">' + esc(env.name) + '</span>' + (count ? '<span class="g4t-count">' + count + '</span>' : '');
        els.envBtn.setAttribute('aria-label', 'Environment: ' + env.name + ' (' + count + ' variables)');
    }
    function renderEnvs() {
        if (!state.envsOpen) { els.envs.innerHTML = ''; return; }
        var env = editedEnv(), servers = state.model ? state.model.servers : [];
        var tabs = state.envs.map(function (e) {
            return '<button type="button" role="tab" class="g4t-env-tab" aria-selected="' + (e.id === env.id) + '" data-action="env-select" data-value="' + esc(e.id) + '">' + (e.id === state.envId ? '<span class="g4t-dot g4t-dot-on" title="Active"></span>' : '') + '<span>' + esc(e.name) + '</span></button>';
        }).join('') + '<button type="button" class="g4t-env-tab g4t-env-add" data-action="env-new">+ New</button>';
        var rows = (env.vars || []).map(function (v, i) {
            return '<div class="g4t-var-row"><input class="g4t-mono" data-bind="env-key" data-index="' + i + '" value="' + esc(v.key) + '" placeholder="name" aria-label="Variable name" spellcheck="false" autocomplete="off">' +
                '<input class="g4t-mono" data-bind="env-value" data-index="' + i + '" value="' + esc(v.value) + '" placeholder="value" aria-label="Variable value" spellcheck="false" autocomplete="off">' +
                '<button type="button" class="g4t-item-remove" data-action="env-remove-var" data-index="' + i + '" aria-label="Remove variable ' + esc(v.key) + '">−</button></div>';
        }).join('');
        els.envs.innerHTML = '<div class="g4t-modal" data-action="close-envs-backdrop"><div class="g4t-login-dialog g4t-env-dialog" role="dialog" aria-modal="true" aria-labelledby="g4t-env-title"><div class="g4t-env-body">' +
            '<div class="g4t-env-head"><h2 id="g4t-env-title">Environments</h2><button type="button" class="g4t-file-clear" data-action="close-envs" aria-label="Close">×</button></div>' +
            '<p>Use <code class="g4t-mono">{{name}}</code> in any parameter, form field, header or JSON body; it is replaced when the request is sent. <code class="g4t-mono">{{token}}</code> is the current bearer token.</p>' +
            '<div class="g4t-env-tabs" role="tablist" aria-label="Environments">' + tabs + '</div>' +
            '<label>Name<input data-bind="env-name" value="' + esc(env.name) + '" autocomplete="off"></label>' +
            '<label>Base URL<input class="g4t-mono" data-bind="env-url" value="' + esc(env.baseUrl) + '" list="g4t-env-servers" placeholder="Same server as the page" autocomplete="off" spellcheck="false"></label>' +
            '<datalist id="g4t-env-servers">' + servers.map(function (sv) { return '<option value="' + esc(sv.url) + '">' + esc(sv.label) + '</option>'; }).join('') + '</datalist>' +
            '<div style="display:grid;gap:6px"><span style="font-size:13px;font-weight:500">Variables</span>' + (rows || '<span class="g4t-muted" style="font-size:13px">No variables yet.</span>') +
            '<button type="button" class="g4t-btn g4t-add-item" data-action="env-add-var" style="justify-self:start">+ Add variable</button></div>' +
            '<div style="display:grid;gap:6px"><span style="font-size:13px;font-weight:500">Headers <span class="g4t-muted" style="font-weight:400">sent with every request (values may use {{variables}})</span></span>' +
            ((env.headers || []).map(function (h, i) {
                return '<div class="g4t-var-row"><input class="g4t-mono" data-bind="env-hkey" data-index="' + i + '" value="' + esc(h.key) + '" placeholder="X-Tenant" aria-label="Header name" spellcheck="false" autocomplete="off">' +
                    '<input class="g4t-mono" data-bind="env-hvalue" data-index="' + i + '" value="' + esc(h.value) + '" placeholder="value" aria-label="Header value" spellcheck="false" autocomplete="off">' +
                    '<button type="button" class="g4t-item-remove" data-action="env-remove-header" data-index="' + i + '" aria-label="Remove header ' + esc(h.key) + '">−</button></div>';
            }).join('') || '<span class="g4t-muted" style="font-size:13px">No headers yet.</span>') +
            '<button type="button" class="g4t-btn g4t-add-item" data-action="env-add-header" style="justify-self:start">+ Add header</button></div>' +
            '<div class="g4t-actions" style="justify-content:space-between"><span class="g4t-actions">' +
            (state.envs.length > 1 ? '<button type="button" class="g4t-btn g4t-btn-danger" data-action="env-delete">Delete</button>' : '') +
            '<button type="button" class="g4t-btn" data-action="env-duplicate">Duplicate</button></span><span class="g4t-actions">' +
            (env.id !== state.envId ? '<button type="button" class="g4t-btn" data-action="env-activate">Use this environment</button>' : '<span class="g4t-muted" style="font-size:12.5px">Active</span>') +
            '<button type="button" class="g4t-btn g4t-btn-primary" data-action="close-envs">Done</button></span></div>' +
            '</div></div></div>';
    }
    function afterEnvChange() {
        renderEnvButton(); renderInfo();
        Object.keys(state.trying).concat(Object.keys(state.codeOpen)).forEach(function (id) { if (state.trying[id] || state.codeOpen[id]) { renderOp(id); } });
    }

    /* ---------- download ---------- */

    function yamlScalar(value) {
        if (value === null || value === undefined) { return 'null'; }
        if (typeof value === 'boolean' || typeof value === 'number') { return String(value); }
        var text = String(value);
        var plain = /^[A-Za-z_\/][A-Za-z0-9_ .\/@()-]*$/.test(text) && !/\s$/.test(text) && !/^(true|false|null|yes|no|on|off|y|n|~)$/i.test(text);
        return plain ? text : JSON.stringify(text);
    }
    /** Minimal JSON → YAML (block style; strings quoted when needed, JSON-style escapes are valid YAML). */
    function toYaml(value, indent) {
        indent = indent || '';
        if (Array.isArray(value)) {
            if (!value.length) { return indent + '[]'; }
            return value.map(function (item) {
                if (item !== null && typeof item === 'object' && Object.keys(item).length) {
                    var inner = toYaml(item, indent + '  ');
                    return indent + '- ' + inner.slice(indent.length + 2);
                }
                return indent + '- ' + (item !== null && typeof item === 'object' ? (Array.isArray(item) ? '[]' : '{}') : yamlScalar(item));
            }).join('\n');
        }
        if (value !== null && typeof value === 'object') {
            var keys = Object.keys(value);
            if (!keys.length) { return indent + '{}'; }
            return keys.map(function (k) {
                var v = value[k], key = indent + yamlScalar(k) + ':';
                if (v !== null && typeof v === 'object' && Object.keys(v).length) { return key + '\n' + toYaml(v, indent + '  '); }
                return key + ' ' + (v !== null && typeof v === 'object' ? (Array.isArray(v) ? '[]' : '{}') : yamlScalar(v));
            }).join('\n');
        }
        return indent + yamlScalar(value);
    }
    function renderDownload() {
        els.download.hidden = !state.downloadOpen;
        els.downloadBtn.setAttribute('aria-expanded', state.downloadOpen ? 'true' : 'false');
        if (!state.downloadOpen) { return; }
        els.download.innerHTML = '<div class="g4t-menu-hint" style="font-size:12px">OpenAPI document · ' + esc(state.version) + '</div>' +
            '<button type="button" role="menuitem" class="g4t-btn" data-action="download" data-value="json">' + ICON.download + 'Download JSON</button>' +
            '<button type="button" role="menuitem" class="g4t-btn" data-action="download" data-value="yaml">' + ICON.download + 'Download YAML</button>';
    }
    function downloadSpec(format) {
        if (!state.spec) { toast('The API definition is not loaded yet'); return; }
        var text = format === 'yaml' ? toYaml(state.spec) + '\n' : JSON.stringify(state.spec, null, 2) + '\n';
        var blob = new Blob([text], { type: format === 'yaml' ? 'application/yaml' : 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'openapi-' + slug(state.version) + '.' + format;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
        state.downloadOpen = false; renderDownload();
        toast('Downloaded ' + a.download);
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
                (activeEnv().baseUrl ? '<span class="g4t-muted">→ ' + esc(serverUrl()) + ' (' + esc(activeEnv().name) + ')</span>' : '') +
                '<span><span class="g4t-muted" style="display:inline-flex">' + ICON.lock.replace('width="14" height="14"', 'width="13" height="13"') + '</span>' + esc(schemeLabel()) + ' · ' + securedCount() + ' of ' + total + ' operations protected</span>' +
                '<button type="button" class="g4t-btn" style="height:28px" data-action="open-token">' + ICON.key + (hasAnyCred() ? (onlyBearer() ? 'Change token' : 'Change credentials') : 'Authorize') + '</button></div>' : '');
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
        if (p.in !== 'path' && !p.required) { return ''; }
        var s = resolve(p.schema || {}).schema;
        if (p.example !== undefined) { return displayValue(p.example); }
        if (s.example !== undefined) { return displayValue(s.example); }
        if (s.default !== undefined) { return displayValue(s.default); }
        if (p.in === 'path') { return schemaType(s) === 'integer' || schemaType(s) === 'number' ? '1' : ''; }
        return '';
    }
    /** A value as it goes into a text-like input (lists comma separated, booleans 1/0). */
    function displayValue(value) {
        if (value == null) { return ''; }
        if (Array.isArray(value)) { return value.filter(function (v) { return v === null || typeof v !== 'object'; }).join(', '); }
        if (typeof value === 'object') { return JSON.stringify(value); }
        if (typeof value === 'boolean') { return value ? '1' : '0'; }
        return String(value);
    }
    /** Which control a schema gets in the Try it out form. */
    function controlKind(raw, binary) {
        var s = resolve(raw).schema, type = schemaType(s);
        if (binary) { return 'file'; }
        if (s.enum) { return 'enum'; }
        if (type === 'boolean') { return 'bool'; }
        if (type === 'integer' || type === 'number') { return 'number'; }
        if (type === 'array') { return 'list'; }
        if (s.format === 'date') { return 'date'; }
        if (s.format === 'date-time') { return 'datetime'; }
        if (s.format === 'email') { return 'email'; }
        return 'text';
    }
    /** Example shown as placeholder (never the generic "string"). */
    function placeholderFor(raw, kind) {
        if (kind === 'list') { return 'value 1, value 2'; }
        var ex = displayValue(sample(raw));
        return ex === 'string' || ex === '' ? typeLabel(raw) : ex;
    }
    /** Pre-filled value: required fields get their example, optional ones start empty and are not sent. */
    function initialValue(raw, required, kind) {
        if (!required || kind === 'file') { return ''; }
        var ex = sample(raw);
        if (kind === 'datetime' && typeof ex === 'string') { return ex.replace(/(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/, '').slice(0, 16); }
        var value = displayValue(ex);
        return value === 'string' ? '' : value;
    }
    function bodyText(op) {
        if (state.bodies[op.id] != null) { return state.bodies[op.id]; }
        var value = sample(bodySchema(op));
        return pretty(value);
    }
    /** Required arrays start with their example items; optional ones with one empty item (not sent). */
    function initialList(raw, required) {
        if (!required) { return ['']; }
        var ex = sample(raw);
        var items = (Array.isArray(ex) ? ex : [ex]).map(displayValue).map(function (v) { return v === 'string' ? '' : v; });
        return items.length ? items : [''];
    }
    /** ".jpg,.png" from "Allowed types: jpg, png" or a bare "jpg,png" description. */
    function acceptFromDescription(text) {
        var m = /types?\s*:\s*([a-z0-9][a-z0-9 ,]*)/i.exec(text) || /^\s*([a-z0-9]+(?:\s*,\s*[a-z0-9]+)*)\s*$/i.exec(text);
        return m ? m[1].split(',').map(function (t) { return t.trim(); }).filter(Boolean).map(function (t) { return '.' + t.toLowerCase(); }).join(',') : '';
    }
    /** multipart: one field per top-level property; "a[b]" names are grouped under "a". */
    function formFields(op) {
        var s = resolve(bodySchema(op)).schema, required = s.required || [];
        return Object.keys(s.properties || {}).map(function (name) {
            var prop = s.properties[name], ps = resolve(prop).schema, binary = isBinary(prop);
            var kind = controlKind(prop, binary), req = required.indexOf(name) !== -1;
            var saved = (state.forms[op.id] || {})[name];
            var grouped = /\[\]$/.test(name) ? null : name.match(/^([^\[\]]+)\[(.+)\]$/);
            var mimes = binary ? acceptFromDescription(ps.description || (ps.items && resolve(ps.items).schema.description) || '') : '';
            return {
                name: name, label: grouped ? grouped[2].replace(/\]\[/g, ' › ') : name.replace(/\[\]$/, ''),
                group: grouped ? grouped[1] : null, schema: prop, kind: kind, req: req, binary: binary,
                multiple: schemaType(ps) === 'array', enumValues: ps.enum || null, accept: mimes,
                value: saved != null ? saved : (kind === 'list' ? initialList(prop, req) : initialValue(prop, req, kind)),
                type: typeLabel(prop), note: noteFor(prop)
            };
        });
    }

    /* ---------- environments & variables ---------- */

    function loadJson(key, fallback) { try { var v = localStorage.getItem('g4t-ui:' + key); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; } }
    function saveJson(key, value) { try { localStorage.setItem('g4t-ui:' + key, JSON.stringify(value)); } catch (e) { } }
    var VAR_RE = /\{\{\s*([A-Za-z_][\w.-]*)\s*\}\}/g;
    function activeEnv() { return state.envs.filter(function (e) { return e.id === state.envId; })[0] || state.envs[0]; }
    function envVars() {
        var vars = {};
        (activeEnv().vars || []).forEach(function (v) { if (v.key) { vars[v.key] = v.value; } });
        if (state.token && !Object.prototype.hasOwnProperty.call(vars, 'token')) { vars.token = state.token; }
        return vars;
    }
    /** Replaces {{name}} with the active environment's variables; unknown names are collected in `missing`. */
    function subst(value, missing) {
        if (typeof value !== 'string' || value.indexOf('{{') === -1) { return value; }
        var vars = envVars();
        return value.replace(VAR_RE, function (match, name) {
            if (Object.prototype.hasOwnProperty.call(vars, name)) { return String(vars[name]); }
            if (missing && missing.indexOf(name) === -1) { missing.push(name); }
            return match;
        });
    }
    function serverUrl() {
        var base = subst(activeEnv().baseUrl || '');
        return (base || state.server || location.origin).replace(/\/+$/, '');
    }

    /* ---------- requests ---------- */

    /**
     * The request as data: url, headers, and either `parts` (multipart fields/files) or `json`.
     * `body` is what fetch() sends; code samples are generated from the rest.
     */
    function buildRequest(op) {
        var missingVars = [], missing = [];
        var path = op.path, query = [], headers = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        // Environment headers first, so documented header parameters and credentials can override them.
        (activeEnv().headers || []).forEach(function (h) { if (h.key) { headers[h.key] = subst(h.value, missingVars); } });
        op.params.forEach(function (p) {
            var value = subst(paramValue(op, p), missingVars);
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
        applyAuth(op, headers, query);
        var req = { method: op.method, url: serverUrl() + path + (query.length ? '?' + query.join('&') : ''), headers: headers, body: undefined, parts: null, json: undefined, missingVars: missingVars };
        if (op.body && ['GET', 'HEAD'].indexOf(op.method) === -1) {
            if (isMultipart(op)) {
                var form = new FormData(), parts = [];
                formFields(op).forEach(function (f) {
                    if (f.binary) {
                        var files = f.multiple ? fileSlots(op.id, f.name).filter(Boolean) : Array.prototype.slice.call((state.files[op.id] || {})[f.name] || []);
                        files.forEach(function (file) { form.append(f.name, file); parts.push({ name: f.name, file: file.name }); });
                        return;
                    }
                    var values = f.multiple ? listValues(op, f).map(function (v) { return String(v).trim(); }).filter(function (v) { return v !== ''; }) : (f.value !== '' ? [f.value] : []);
                    values.forEach(function (v) {
                        v = subst(String(v), missingVars);
                        form.append(f.name, v);
                        parts.push({ name: f.name, value: v });
                    });
                });
                req.body = form;
                req.parts = parts;
            } else {
                var json;
                try { json = JSON.parse(subst(bodyText(op), missingVars)); } catch (e) { return { error: 'Request body isn’t valid JSON' }; }
                headers['Content-Type'] = currentCtype(op) || 'application/json';
                req.json = json;
                req.body = JSON.stringify(json);
            }
        }
        return req;
    }
    /** Same as buildRequest, but never fails: a missing path parameter keeps its {placeholder}. */
    function sampleRequest(op) {
        var req = buildRequest(op);
        return req.error ? { method: op.method, url: serverUrl() + op.path, headers: { 'Accept': 'application/json' }, parts: null, json: undefined } : req;
    }
    function shownHeaders(req) {
        return Object.keys(req.headers).filter(function (h) { return h !== 'X-Requested-With'; }).filter(function (h) { return h !== 'Content-Type' || req.json !== undefined; });
    }

    /* ---------- code samples ---------- */

    var CODE_LANGS = [['curl', 'cURL'], ['js', 'JavaScript'], ['dart', 'Dart (Dio)'], ['php', 'PHP (Laravel)']];
    function codeFor(op, lang) {
        var req = sampleRequest(op);
        return ({ curl: codeCurl, js: codeJs, dart: codeDart, php: codePhp }[lang] || codeCurl)(req);
    }
    function sq(text) { return "'" + String(text).replace(/'/g, "'\\''") + "'"; }
    function codeCurl(req) {
        var lines = ['curl -X ' + req.method + ' ' + sq(req.url)];
        shownHeaders(req).forEach(function (h) { lines.push('-H ' + sq(h + ': ' + req.headers[h])); });
        (req.parts || []).forEach(function (part) { lines.push('-F ' + sq(part.name + '=' + (part.file !== undefined ? '@' + part.file : part.value))); });
        if (req.json !== undefined) { lines.push('-d ' + sq(JSON.stringify(req.json))); }
        return lines.join(' \\\n  ');
    }
    /** value → source literal; `lang` picks the syntax for maps / strings. */
    function literal(value, lang, indent) {
        indent = indent || '';
        var next = indent + (lang === 'php' ? '    ' : '  ');
        var str = function (v) {
            if (lang === 'php') { return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'"; }
            if (lang === 'dart') { return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\$/g, '\\$').replace(/\n/g, '\\n') + "'"; }
            return JSON.stringify(String(v));
        };
        if (value === null || value === undefined) { return 'null'; }
        if (typeof value === 'string') { return str(value); }
        if (typeof value === 'number' || typeof value === 'boolean') { return String(value); }
        var open = lang === 'php' ? '[' : (Array.isArray(value) ? '[' : '{'), close = lang === 'php' ? ']' : (Array.isArray(value) ? ']' : '}');
        var entries = Array.isArray(value)
            ? value.map(function (v) { return next + literal(v, lang, next); })
            : Object.keys(value).map(function (k) {
                var key = lang === 'js' && /^[A-Za-z_$][\w$]*$/.test(k) ? k : str(k);
                return next + key + (lang === 'php' ? ' => ' : ': ') + literal(value[k], lang, next);
            });
        return entries.length ? open + '\n' + entries.join(',\n') + ',\n' + indent + close : open + close;
    }
    function codeJs(req) {
        var headers = {};
        shownHeaders(req).forEach(function (h) { headers[h] = req.headers[h]; });
        var out = [];
        if (req.parts) {
            out.push('const form = new FormData();');
            req.parts.forEach(function (part) {
                out.push(part.file !== undefined
                    ? 'form.append(' + JSON.stringify(part.name) + ', fileInput.files[0]); // ' + part.file
                    : 'form.append(' + JSON.stringify(part.name) + ', ' + JSON.stringify(part.value) + ');');
            });
            out.push('');
        }
        out.push('const response = await fetch(' + JSON.stringify(req.url) + ', {');
        out.push('  method: ' + JSON.stringify(req.method) + ',');
        out.push('  headers: ' + literal(headers, 'js', '  ') + ',');
        if (req.parts) { out.push('  body: form,'); }
        if (req.json !== undefined) { out.push('  body: JSON.stringify(' + literal(req.json, 'js', '  ') + '),'); }
        out.push('});');
        out.push('const data = await response.json();');
        return out.join('\n');
    }
    function codeDart(req) {
        var headers = {};
        shownHeaders(req).forEach(function (h) { headers[h] = req.headers[h]; });
        var out = ["import 'package:dio/dio.dart';", '', 'final dio = Dio();'];
        var data = null;
        if (req.parts) {
            out.push('final formData = FormData();');
            req.parts.forEach(function (part) {
                out.push(part.file !== undefined
                    ? 'formData.files.add(MapEntry(' + literal(part.name, 'dart') + ', await MultipartFile.fromFile(' + literal(part.file, 'dart') + ')));'
                    : 'formData.fields.add(const MapEntry(' + literal(part.name, 'dart') + ', ' + literal(part.value, 'dart') + '));');
            });
            data = 'formData';
        } else if (req.json !== undefined) {
            data = literal(req.json, 'dart', '  ');
        }
        out.push('', 'final response = await dio.request(', '  ' + literal(req.url, 'dart') + ',');
        if (data) { out.push('  data: ' + data + ','); }
        out.push('  options: Options(', '    method: ' + literal(req.method, 'dart') + ',', '    headers: ' + literal(headers, 'dart', '    ') + ',', '  ),', ');', 'print(response.data);');
        return out.join('\n');
    }
    function codePhp(req) {
        var out = ['use Illuminate\\Support\\Facades\\Http;', '', '$response = Http::acceptJson()'];
        var bearer = /^Bearer\s+(.+)$/.exec(req.headers['Authorization'] || '');
        if (bearer) { out.push('    ->withToken(' + literal(bearer[1], 'php') + ')'); }
        var extra = {};
        shownHeaders(req).forEach(function (h) { if (['Accept', 'Content-Type'].indexOf(h) === -1 && !(h === 'Authorization' && bearer)) { extra[h] = req.headers[h]; } });
        if (Object.keys(extra).length) { out.push('    ->withHeaders(' + literal(extra, 'php', '    ') + ')'); }
        var method = req.method.toLowerCase(), args = '';
        if (req.parts) {
            out.push('    ->asMultipart()');
            req.parts.filter(function (p) { return p.file !== undefined; }).forEach(function (part) {
                out.push('    ->attach(' + literal(part.name, 'php') + ', file_get_contents(' + literal(part.file, 'php') + '), ' + literal(part.file, 'php') + ')');
            });
            var fields = req.parts.filter(function (p) { return p.file === undefined; }).map(function (p) { return { name: p.name, contents: p.value }; });
            if (fields.length) { args = ', ' + literal(fields, 'php', '    '); }
        } else if (req.json !== undefined) {
            args = ', ' + literal(req.json, 'php', '    ');
        }
        out.push('    ->' + (['get', 'post', 'put', 'patch', 'delete', 'head'].indexOf(method) !== -1 ? method : 'send') + '(' + (['get', 'post', 'put', 'patch', 'delete', 'head'].indexOf(method) === -1 ? literal(req.method, 'php') + ', ' : '') + literal(req.url, 'php') + args + ');', '', '$data = $response->json();');
        return out.join('\n');
    }
    function codePanelHtml(op) {
        var lang = state.codeLang;
        return '<div class="g4t-code-panel"><div class="g4t-ex-tabs" role="tablist" aria-label="Code sample">' + CODE_LANGS.map(function (l) {
            return '<button type="button" role="tab" aria-selected="' + (l[0] === lang) + '" data-action="code-lang" data-op="' + esc(op.id) + '" data-value="' + l[0] + '">' + esc(l[1]) + '</button>';
        }).join('') + '</div><div class="g4t-code-wrap"><pre class="g4t-code is-muted">' + esc(codeFor(op, lang)) + '</pre><button type="button" class="g4t-copy" aria-label="Copy code" data-action="copy-code" data-op="' + esc(op.id) + '">' + ICON.copy + '</button></div></div>';
    }
    function curl(op) { return codeFor(op, 'curl'); }

    function execute(op) {
        var req = buildRequest(op);
        if (req.error) { toast(req.error); return; }
        if (req.missingVars.length) {
            // Sending "{{name}}" literally only produces a confusing 404 / 422.
            toast('Unknown variable' + (req.missingVars.length > 1 ? 's' : '') + ': ' + req.missingVars.map(function (n) { return '{{' + n + '}}'; }).join(', ') + ' · define it in Environments');
            return;
        }
        state.busy[op.id] = true; renderOp(op.id);
        var started = performance.now();
        fetch(req.url, { method: req.method, headers: req.headers, body: req.body })
            .then(function (response) {
                return response.text().then(function (text) {
                    var json; try { json = text ? JSON.parse(text) : null; } catch (e) { json = undefined; }
                    state.results[op.id] = { code: response.status, text: statusText(response.status, response.statusText), ms: Math.round(performance.now() - started), json: json, raw: text, ctype: (response.headers.get('content-type') || '').split(';')[0], unauth: response.status === 401 && !opAuthReady(op) };
                });
            })
            .catch(function (error) {
                state.results[op.id] = { code: 0, text: 'Network error', ms: Math.round(performance.now() - started), json: undefined, raw: error.message + '\n\nThe request did not reach the server. Check the server URL above and CORS.', ctype: '' };
            })
            .then(function () {
                var res = state.results[op.id];
                addHistory(op, { at: Date.now(), code: res.code, text: res.text, ms: res.ms, method: req.method, url: req.url, env: activeEnv().name, input: snapshot(op.id), files: (req.parts || []).filter(function (p) { return p.file !== undefined; }).map(function (p) { return p.file; }) });
                state.busy[op.id] = false; renderOp(op.id);
            });
    }

    /* ---------- saved inputs & history ---------- */

    function snapshot(id) {
        return JSON.parse(JSON.stringify({ inputs: state.inputs[id] || null, forms: state.forms[id] || null, body: state.bodies[id] != null ? state.bodies[id] : null, ctype: state.ctype[id] || null }));
    }
    function applySnapshot(id, snap) {
        snap = snap || {};
        if (snap.inputs) { state.inputs[id] = snap.inputs; } else { delete state.inputs[id]; }
        if (snap.forms) { state.forms[id] = snap.forms; } else { delete state.forms[id]; }
        if (snap.body != null) { state.bodies[id] = snap.body; } else { delete state.bodies[id]; }
        if (snap.ctype) { state.ctype[id] = snap.ctype; } else { delete state.ctype[id]; }
    }
    var persistTimer = null;
    /** Try it out values survive reloads (files cannot be stored by the browser). */
    function persistInputs() {
        clearTimeout(persistTimer);
        persistTimer = setTimeout(function () {
            var saved = {};
            Object.keys(state.inputs).concat(Object.keys(state.forms), Object.keys(state.bodies), Object.keys(state.ctype)).forEach(function (id) { saved[id] = snapshot(id); });
            saveJson('inputs', saved);
        }, 250);
    }
    function restoreInputs() {
        var saved = loadJson('inputs', {});
        Object.keys(saved || {}).forEach(function (id) { applySnapshot(id, saved[id]); });
    }
    function resetInputs(id) {
        applySnapshot(id, null);
        delete state.files[id];
        persistInputs();
    }
    var HISTORY_LIMIT = 10;
    function historyOf(id) { var all = loadJson('history', {}); return (all && all[id]) || []; }
    function addHistory(op, entry) {
        var all = loadJson('history', {}) || {};
        all[op.id] = [entry].concat(all[op.id] || []).slice(0, HISTORY_LIMIT);
        saveJson('history', all);
    }
    function clearHistory(id) { var all = loadJson('history', {}) || {}; delete all[id]; saveJson('history', all); }
    function ago(ts) {
        var sec = Math.max(0, Math.round((Date.now() - ts) / 1000));
        if (sec < 60) { return sec + 's ago'; }
        if (sec < 3600) { return Math.round(sec / 60) + 'm ago'; }
        if (sec < 86400) { return Math.round(sec / 3600) + 'h ago'; }
        return new Date(ts).toLocaleDateString();
    }
    function historyHtml(op) {
        var list = historyOf(op.id);
        if (!list.length) { return '<div class="g4t-history-empty">No requests yet. Executed requests show up here.</div>'; }
        return '<div class="g4t-history">' + list.map(function (h, i) {
            var shortUrl = h.url.replace(/^https?:\/\/[^/]+/, '');
            return '<div class="g4t-history-row"><span class="g4t-status ' + statusClass(h.code) + '">' + (h.code || '—') + '</span>' +
                '<span class="g4t-history-url g4t-mono" title="' + esc(h.url) + '">' + esc(shortUrl) + '</span>' +
                '<span class="g4t-muted g4t-history-meta">' + h.ms + ' ms · ' + esc(ago(h.at)) + (h.env ? ' · ' + esc(h.env) : '') + (h.files && h.files.length ? ' · ' + h.files.length + ' file' + (h.files.length > 1 ? 's' : '') : '') + '</span>' +
                '<span class="g4t-history-actions"><button type="button" class="g4t-btn" data-action="history-restore" data-op="' + esc(op.id) + '" data-value="' + i + '">Restore</button><button type="button" class="g4t-btn" data-action="history-run" data-op="' + esc(op.id) + '" data-value="' + i + '">Re-run</button></span></div>';
        }).join('') + '<div class="g4t-actions" style="justify-content:flex-end"><button type="button" class="g4t-link-btn" style="font-size:12px;font-weight:500" data-action="history-clear" data-op="' + esc(op.id) + '">Clear history</button></div></div>';
    }

    /** Multipart schemas flatten names ("tags[]", "dimensions[width]"); show them like the JSON shape. */
    function groupBracketRows(rows) {
        var out = [], groups = {};
        rows.forEach(function (r) {
            if (r.level) { out.push(r); return; }
            if (/\[\]$/.test(r.name)) { out.push(Object.assign({}, r, { name: r.name.slice(0, -2) })); return; }
            var m = r.name.match(/^([^\[\]]+)\[(.+)\]$/);
            if (!m) { out.push(r); return; }
            if (!groups[m[1]]) {
                groups[m[1]] = true;
                out.push({ name: m[1], type: 'object', req: false, note: '—', level: 0 });
            }
            out.push(Object.assign({}, r, { name: m[2].replace(/\]\[/g, ' › '), level: 1 }));
        });
        return out;
    }

    function rowsHtml(rows) {
        return '<div class="g4t-table">' + rows.map(function (r) {
            return '<div class="g4t-row"><div class="g4t-row-name' + (r.level ? ' is-nested' : '') + '"' + (r.level > 1 ? ' style="margin-inline-start:' + ((r.level - 1) * 12) + 'px"' : '') + '><div class="g4t-field"><span class="g4t-field-name">' + esc(r.name) + '</span>' + (r.req ? '<span class="g4t-req">required</span>' : '') + '</div><div class="g4t-type">' + esc(r.type) + (r.in ? ' · ' + esc(r.in) : '') + '</div></div><div class="g4t-note">' + esc(r.note) + '</div></div>';
        }).join('') + '</div>';
    }

    /** Hint under a form control: description and bounds; enum values and "nullable" are left to the control. */
    function formHint(raw, fallback) {
        var s = resolve(raw || {}).schema, parts = [];
        if (s.description) { parts.push(s.description); }
        var min = s.minLength != null ? s.minLength : s.minimum != null ? s.minimum : s.minItems;
        var max = s.maxLength != null ? s.maxLength : s.maximum != null ? s.maximum : s.maxItems;
        if (min != null) { parts.push('min ' + min); }
        if (max != null) { parts.push('max ' + max); }
        if (!parts.length && fallback && fallback !== '—' && !s.enum && fallback !== 'nullable') { parts.push(fallback); }
        return parts.join(' · ');
    }
    /** One labelled row of the Try it out form; the control matches the field type. */
    function formRowHtml(f) {
        var id = 'g4t-f-' + f.op.id + '-' + f.name, control, hint = '';
        var attrs = ' data-bind="' + (f.kind === 'file' ? 'file' : f.bind) + '" data-op="' + esc(f.op.id) + '" data-name="' + esc(f.name) + '" id="' + esc(id) + '"';
        if (f.multiple && f.bind === 'form') {
            // Arrays: one control per item, like Swagger UI's "Add item" (files: one file per slot).
            var isFile = f.kind === 'file';
            var itemSchema = resolve(f.schema).schema.items || {};
            var itemKind = isFile ? 'file' : controlKind(itemSchema, false);
            if (itemKind === 'list') { itemKind = 'text'; }
            var items = isFile ? fileSlots(f.op.id, f.name) : listValues(f.op, f);
            control = '<div class="g4t-items">' + items.map(function (item, i) {
                var itemAttrs = ' data-bind="' + (isFile ? 'file-item' : 'form-item') + '" data-op="' + esc(f.op.id) + '" data-name="' + esc(f.name) + '" data-index="' + i + '"' + (i === 0 ? ' id="' + esc(id) + '"' : '') + ' aria-label="' + esc(f.label + ' item ' + (i + 1)) + '"';
                return '<div class="g4t-item">' + controlHtml(f, itemKind, isFile ? '' : item, itemAttrs, isFile ? (item ? [item] : []) : null, itemSchema, false) +
                    '<button type="button" class="g4t-item-remove" data-action="remove-item" data-op="' + esc(f.op.id) + '" data-name="' + esc(f.name) + '" data-index="' + i + '" aria-label="Remove ' + esc(f.label) + ' item ' + (i + 1) + '">−</button></div>';
            }).join('') +
                '<button type="button" class="g4t-btn g4t-add-item" data-action="add-item" data-op="' + esc(f.op.id) + '" data-name="' + esc(f.name) + '" data-kind="' + (isFile ? 'file' : 'value') + '">+ Add ' + (isFile ? 'file' : 'item') + '</button></div>';
            if (!items.length) { hint = 'Nothing is sent for an empty list.'; }
        } else {
            var files = f.kind === 'file' ? Array.prototype.slice.call((state.files[f.op.id] || {})[f.name] || []) : null;
            control = controlHtml(f, f.kind, f.value, attrs, files, f.schema, true);
            if (f.kind === 'list') { hint = 'Separate values with commas.'; }
        }
        var note = formHint(f.schema, f.note);
        hint = [note, hint].filter(Boolean).join(' · ');
        return '<div class="g4t-form-row' + (f.nested ? ' is-nested' : '') + '"><label class="g4t-form-label" for="' + esc(id) + '"><span class="g4t-field"><span class="g4t-field-name">' + esc(f.label) + '</span>' + (f.req ? '<span class="g4t-req">required</span>' : '') + '</span><span class="g4t-type">' + esc(f.type) + '</span></label>' +
            '<div class="g4t-form-control">' + control + (hint ? '<div class="g4t-form-hint">' + esc(hint) + '</div>' : '') + '</div></div>';
    }

    /** A single control: file picker, select (enum / boolean) or a typed input. */
    function controlHtml(f, kind, value, attrs, files, schema, clearable) {
        switch (kind) {
            case 'file':
                var names = (files || []).map(function (file) { return file.name; }).join(', ');
                return '<div class="g4t-file"><label class="g4t-btn g4t-file-btn">' + ICON.upload + 'Choose file' +
                    '<input type="file" class="g4t-sr"' + attrs + (f.accept ? ' accept="' + esc(f.accept) + '"' : '') + '></label>' +
                    '<span class="g4t-file-name' + (names ? '' : ' is-empty') + '" title="' + esc(names) + '">' + esc(names || 'No file chosen') + '</span>' +
                    (names && clearable ? '<button type="button" class="g4t-file-clear" data-action="clear-file" data-op="' + esc(f.op.id) + '" data-name="' + esc(f.name) + '" aria-label="Remove ' + esc(f.label) + '">×</button>' : '') + '</div>';
            case 'enum':
            case 'bool':
                var enumValues = kind === 'enum' ? (resolve(schema || {}).schema.enum || f.enumValues || []) : [];
                var options = kind === 'bool' ? [['1', 'true'], ['0', 'false']] : enumValues.map(function (v) { return [String(v), String(v)]; });
                return '<select' + attrs + '>' + (f.req && value !== '' ? '' : '<option value="">' + (f.req ? 'Select…' : '— not sent —') + '</option>') +
                    options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] === String(value) ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select>';
            default:
                // Numbers stay type="text" (numeric keyboard via inputmode) so {{variables}} can be typed in.
                var type = { date: 'date', datetime: 'datetime-local', email: 'email' }[kind] || 'text';
                return '<input type="' + type + '"' + attrs + ' value="' + esc(value) + '" placeholder="' + esc(placeholderFor(schema || {}, kind)) + '"' + (kind === 'number' ? ' inputmode="decimal"' : '') + ' autocomplete="off" spellcheck="false">';
        }
    }
    /** File slots of an array-of-files field (one File or null per slot). */
    function fileSlots(opId, name) {
        var value = (state.files[opId] || {})[name];
        return Array.isArray(value) ? value : [null];
    }
    /** Items of an array field as strings. */
    function listValues(op, f) {
        var saved = (state.forms[op.id] || {})[f.name];
        if (Array.isArray(saved)) { return saved; }
        return Array.isArray(f.value) ? f.value : String(f.value || '').split(',').map(function (v) { return v.trim(); });
    }
    function formField(op, name) { return formFields(op).filter(function (f) { return f.name === name; })[0] || null; }

    function tryHtml(op) {
        var server = serverUrl(), html = '', historyCount = historyOf(op.id).length, histOpen = !!state.historyOpen[op.id];
        var env = activeEnv();
        html += '<div class="g4t-try"><div class="g4t-try-title"><h3 style="margin:0;font-size:13px;font-weight:600">Try it out</h3><span>→ ' + esc(server) + (env.id !== 'default' || state.envs.length > 1 ? ' · ' + esc(env.name) : '') + '</span>' +
            '<span class="g4t-try-tools"><button type="button" class="g4t-btn" data-action="toggle-history" data-op="' + esc(op.id) + '" aria-pressed="' + histOpen + '">' + ICON.clock + 'History' + (historyCount ? ' <span class="g4t-count">' + historyCount + '</span>' : '') + '</button>' +
            '<button type="button" class="g4t-btn" data-action="reset-inputs" data-op="' + esc(op.id) + '" title="Forget the values saved for this endpoint">' + ICON.reset + 'Reset</button></span></div>';
        if (histOpen) { html += historyHtml(op); }
        if (op.secured && !opAuthReady(op)) {
            html += '<div class="g4t-hint">' + ICON.lock + '<span>Needs ' + esc(requirementLabel(op)) + '. Requests will return 401 until you add it.</span><button type="button" data-action="' + (S.autoLogin ? 'open-login' : 'open-token') + '">' + (S.autoLogin ? 'Log in' : 'Authorize') + '</button></div>';
        }
        var inputs = op.params.filter(function (p) { return ['path', 'query', 'header'].indexOf(p.in) !== -1; });
        if (inputs.length) {
            html += '<div class="g4t-try-section"><div class="g4t-try-label">Parameters</div><div class="g4t-form">' + inputs.map(function (p) {
                var kind = controlKind(p.schema || {}, false);
                return formRowHtml({
                    bind: 'param', op: op, name: p.name, label: p.name, kind: kind, schema: p.schema || {},
                    req: p.in === 'path' || !!p.required, value: paramValue(op, p), enumValues: resolve(p.schema || {}).schema.enum || null,
                    type: typeLabel(p.schema || {}) + ' · ' + p.in, note: p.description || noteFor(p.schema || {})
                });
            }).join('') + '</div></div>';
        }
        var ctypeTabs = op.ctypes.length > 1 ? '<div class="g4t-ctype" role="tablist" aria-label="Content type">' + op.ctypes.map(function (c) {
            return '<button type="button" role="tab" aria-selected="' + (c === currentCtype(op)) + '" data-action="ctype" data-op="' + esc(op.id) + '" data-value="' + esc(c) + '">' + esc(c) + '</button>';
        }).join('') + '</div>' : '<span class="g4t-mono g4t-muted">' + esc(currentCtype(op) || '') + '</span>';
        if (op.body) {
            if (isMultipart(op)) {
                var seenGroups = {};
                html += '<div class="g4t-try-section"><div class="g4t-try-label">Body ' + ctypeTabs + '<span class="g4t-try-note">Empty optional fields are not sent.</span></div><div class="g4t-form">' + formFields(op).map(function (f) {
                    var head = '';
                    if (f.group && !seenGroups[f.group]) {
                        seenGroups[f.group] = true;
                        head = '<div class="g4t-form-group"><span class="g4t-field-name">' + esc(f.group) + '</span><span class="g4t-type">object</span></div>';
                    }
                    return head + formRowHtml({ bind: 'form', op: op, name: f.name, label: f.label, nested: !!f.group, kind: f.kind, schema: f.schema, req: f.req, value: f.value, enumValues: f.enumValues, multiple: f.multiple, accept: f.accept, type: f.type, note: f.note });
                }).join('') + '</div></div>';
            } else {
                var text = bodyText(op);
                html += '<div class="g4t-try-section"><div class="g4t-try-label">Body ' + ctypeTabs + '</div><textarea class="g4t-textarea" data-bind="body" data-op="' + esc(op.id) + '" rows="' + Math.min(14, text.split('\n').length + 1) + '" spellcheck="false" aria-label="Request body">' + esc(text) + '</textarea></div>';
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
                '<div style="display:grid;gap:6px;min-width:0"><span class="g4t-muted" style="font-size:12px">Request as code</span>' + codePanelHtml(op) + '</div></div>';
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
            '<span class="g4t-op-tail">' + (op.secured ? '<span class="g4t-lock' + (opAuthReady(op) ? ' is-ready' : '') + '" title="' + esc(opAuthReady(op) ? 'Credentials will be sent' : 'Requires ' + requirementLabel(op)) + '">' + ICON.lock + '</span>' : '') + ICON.chev + '</span></button>';
        if (!open) { return html + '</article>'; }

        html += '<div class="g4t-op-body"><div class="g4t-op-intro"><p class="g4t-op-desc">' + esc(op.desc || op.summary || 'No description.') + '</p><div style="display:flex;gap:8px;flex:none">' +
            '<button type="button" class="g4t-btn" data-action="toggle-code" data-op="' + esc(op.id) + '" aria-pressed="' + !!state.codeOpen[op.id] + '">' + ICON.term + 'Code</button>' +
            '<button type="button" class="g4t-try-toggle" data-action="toggle-try" data-op="' + esc(op.id) + '" aria-pressed="' + trying + '">' + (trying ? 'Cancel' : 'Try it out') + '</button></div></div>';
        if (state.codeOpen[op.id]) { html += codePanelHtml(op); }
        // While Try it out is open its form lists the same fields, so the reference tables step aside.
        if (op.params.length && !trying) {
            html += '<div class="g4t-block"><h3>Parameters</h3>' + rowsHtml(op.params.map(function (p) {
                return { name: p.name, in: p.in, type: typeLabel(p.schema || {}), req: !!p.required, note: p.description || noteFor(p.schema || {}), level: 0 };
            })) + '</div>';
        }
        if (op.body && !trying) {
            // The JSON schema nests objects (dimensions › width); the multipart one is flattened (dimensions[width]).
            var docsType = op.ctypes.indexOf('application/json') !== -1 ? 'application/json' : op.ctypes[0];
            var docsSchema = docsType ? op.body.content[docsType].schema || {} : {};
            html += '<div class="g4t-block"><div class="g4t-block-head"><h3>Request body</h3><span class="g4t-mono g4t-muted" style="font-size:12px">' + op.ctypes.map(esc).join(' · ') + '</span></div>';
            var rows = groupBracketRows(fields(docsSchema));
            html += (rows.length ? rowsHtml(rows) : '<div class="g4t-note">' + esc(typeLabel(docsSchema)) + '</div>') + '</div>';
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
            case 'logout': state.creds = {}; saveJson('creds', state.creds); setToken(null, 'Logged out · credentials cleared'); break;
            case 'open-login': openLogin('credentials'); break;
            case 'open-token': openLogin('token'); break;
            case 'login-tab': state.loginTab = value; state.loginError = ''; renderLogin(); break;
            case 'paste-token':
                if (navigator.clipboard && navigator.clipboard.readText) {
                    var pasteScheme = el.getAttribute('data-scheme') || primaryBearer();
                    navigator.clipboard.readText().then(function (text) {
                        var input = els.login.querySelector('input[data-bind="cred"][data-scheme="' + CSS.escape(pasteScheme) + '"]');
                        (state.credDraft[pasteScheme] = state.credDraft[pasteScheme] || {}).value = String(text || '').trim();
                        if (input) { input.value = state.credDraft[pasteScheme].value; input.focus(); }
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
            case 'ctype': state.ctype[id] = value; persistInputs(); renderOp(id); break;
            case 'execute': if (op) { execute(op); } break;
            case 'clear-result': delete state.results[id]; renderOp(id); break;
            case 'clear-file': if (state.files[id]) { delete state.files[id][el.getAttribute('data-name')]; } renderOp(id); break;
            case 'add-item':
            case 'remove-item':
                if (op) {
                    var field = el.getAttribute('data-name'), index = Number(el.getAttribute('data-index'));
                    var isFileList = action === 'add-item' ? el.getAttribute('data-kind') === 'file' : !!(formField(op, field) || {}).binary;
                    var list = isFileList ? fileSlots(id, field).slice() : listValues(op, formField(op, field)).slice();
                    if (action === 'add-item') { list.push(isFileList ? null : ''); } else { list.splice(index, 1); }
                    if (isFileList) { (state.files[id] = state.files[id] || {})[field] = list; } else { (state.forms[id] = state.forms[id] || {})[field] = list; persistInputs(); }
                    renderOp(id);
                    if (action === 'add-item') {
                        var added = document.querySelector('#op-' + CSS.escape(id) + ' [data-name="' + CSS.escape(field) + '"][data-index="' + (list.length - 1) + '"]');
                        if (added && !isFileList) { added.focus(); }
                    }
                }
                break;
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
            case 'toggle-code': state.codeOpen[id] = !state.codeOpen[id]; renderOp(id); break;
            case 'code-lang':
                state.codeLang = value; store.set('codeLang', value);
                Object.keys(state.codeOpen).concat(Object.keys(state.results)).forEach(function (k) { if (opById(k) && document.getElementById('op-' + k)) { renderOp(k); } });
                break;
            case 'copy-code': if (op) { copy(codeFor(op, state.codeLang), (CODE_LANGS.filter(function (l) { return l[0] === state.codeLang; })[0] || ['', 'Code'])[1] + ' copied'); } break;
            case 'toggle-history': state.historyOpen[id] = !state.historyOpen[id]; renderOp(id); break;
            case 'history-clear': clearHistory(id); renderOp(id); toast('History cleared'); break;
            case 'history-restore':
            case 'history-run':
                var entry = historyOf(id)[Number(value)];
                if (entry && op) {
                    applySnapshot(id, entry.input); persistInputs();
                    state.trying[id] = true; renderOp(id);
                    if (action === 'history-run') { execute(op); } else { toast('Values restored' + (entry.files && entry.files.length ? ' · pick the files again' : '')); }
                }
                break;
            case 'reset-inputs': resetInputs(id); renderOp(id); toast('Saved values cleared'); break;
            case 'toggle-download': state.downloadOpen = !state.downloadOpen; state.appearOpen = false; state.authMenuOpen = false; renderDownload(); renderAppear(); renderAuth(); break;
            case 'download': downloadSpec(value); break;
            case 'open-envs':
                state.envsOpen = true; state.envEdit = state.envId; renderEnvs();
                setTimeout(function () { var f = els.envs.querySelector('[data-bind="env-name"]'); if (f) { f.focus(); } }, 20);
                break;
            case 'close-envs': state.envsOpen = false; saveEnvs(); renderEnvs(); afterEnvChange(); break;
            case 'close-envs-backdrop': if (e.target === el) { state.envsOpen = false; saveEnvs(); renderEnvs(); afterEnvChange(); } break;
            case 'env-select': state.envEdit = value; renderEnvs(); break;
            case 'env-activate': state.envId = editedEnv().id; saveEnvs(); renderEnvs(); afterEnvChange(); toast('Environment: ' + activeEnv().name); break;
            case 'env-new':
            case 'env-duplicate':
                var base = action === 'env-duplicate' ? editedEnv() : null;
                var copyPairs = function (list) { return (list || []).map(function (v) { return { key: v.key, value: v.value }; }); };
                var created = { id: newId(), name: base ? base.name + ' copy' : 'Environment ' + (state.envs.length + 1), baseUrl: base ? base.baseUrl : '', vars: base ? copyPairs(base.vars) : [], headers: base ? copyPairs(base.headers) : [] };
                state.envs.push(created); state.envEdit = created.id; saveEnvs(); renderEnvs();
                setTimeout(function () { var f = els.envs.querySelector('[data-bind="env-name"]'); if (f) { f.focus(); f.select(); } }, 20);
                break;
            case 'env-delete':
                if (state.envs.length > 1) {
                    var gone = editedEnv();
                    state.envs = state.envs.filter(function (en) { return en.id !== gone.id; });
                    if (state.envId === gone.id) { state.envId = state.envs[0].id; }
                    state.envEdit = state.envId; saveEnvs(); renderEnvs(); afterEnvChange(); toast('Deleted ' + gone.name);
                }
                break;
            case 'env-add-var':
                editedEnv().vars.push({ key: '', value: '' }); saveEnvs(); renderEnvs();
                var keyInputs = els.envs.querySelectorAll('[data-bind="env-key"]');
                if (keyInputs.length) { keyInputs[keyInputs.length - 1].focus(); }
                break;
            case 'env-remove-var': editedEnv().vars.splice(Number(el.getAttribute('data-index')), 1); saveEnvs(); renderEnvs(); renderEnvButton(); break;
            case 'env-add-header':
                (editedEnv().headers = editedEnv().headers || []).push({ key: '', value: '' }); saveEnvs(); renderEnvs();
                var headerInputs = els.envs.querySelectorAll('[data-bind="env-hkey"]');
                if (headerInputs.length) { headerInputs[headerInputs.length - 1].focus(); }
                break;
            case 'env-remove-header': editedEnv().headers.splice(Number(el.getAttribute('data-index')), 1); saveEnvs(); renderEnvs(); break;
            case 'show-all': state.version = 'all'; store.set('version', 'all'); renderVersions(); load(); break;
            case 'clear-filter': state.filter = ''; els.filter.value = ''; renderNav(); renderOps(); break;
        }
    });

    function setListItem(opId, name, index, value) {
        var op = opById(opId), field = op ? formField(op, name) : null;
        var list = field ? listValues(op, field).slice() : [];
        list[index] = value;
        (state.forms[opId] = state.forms[opId] || {})[name] = list;
    }

    document.addEventListener('input', function (e) {
        var el = e.target, bind = el.getAttribute && el.getAttribute('data-bind');
        if (el === els.filter) { state.filter = el.value; renderNav(); renderOps(); return; }
        var id = el.getAttribute && el.getAttribute('data-op'), name = el.getAttribute && el.getAttribute('data-name');
        if (bind === 'param') { (state.inputs[id] = state.inputs[id] || {})[name] = el.value; }
        else if (bind === 'form') { (state.forms[id] = state.forms[id] || {})[name] = el.value; }
        else if (bind === 'form-item') { setListItem(id, name, Number(el.getAttribute('data-index')), el.value); }
        else if (bind === 'body') { state.bodies[id] = el.value; }
        else if (bind === 'login') { state.loginValues[el.name] = el.value; }
        else if (bind === 'cred') { var credScheme = el.getAttribute('data-scheme'); (state.credDraft[credScheme] = state.credDraft[credScheme] || {})[el.getAttribute('data-field')] = el.value; }
        else if (bind && bind.indexOf('env-') === 0) { editEnvField(el, bind); }
        if (['param', 'form', 'form-item', 'body'].indexOf(bind) !== -1) { persistInputs(); }
    });
    function editEnvField(el, bind) {
        var env = editedEnv(), index = Number(el.getAttribute('data-index'));
        if (bind === 'env-name') { env.name = el.value || 'Environment'; var tab = els.envs.querySelector('.g4t-env-tab[aria-selected="true"]'); if (tab) { tab.lastChild.textContent = env.name; } }
        else if (bind === 'env-url') { env.baseUrl = el.value.trim(); }
        else if (bind === 'env-key') { env.vars[index].key = el.value.trim(); }
        else if (bind === 'env-value') { env.vars[index].value = el.value; }
        else if (bind === 'env-hkey') { env.headers[index].key = el.value.trim(); }
        else if (bind === 'env-hvalue') { env.headers[index].value = el.value; }
        saveEnvs(); renderEnvButton();
    }

    document.addEventListener('change', function (e) {
        var el = e.target, bind = el.getAttribute && el.getAttribute('data-bind');
        var id = el.getAttribute && el.getAttribute('data-op'), name = el.getAttribute && el.getAttribute('data-name');
        if (el === els.version) { state.version = el.value; store.set('version', el.value); state.results = {}; load(); }
        else if (bind === 'server') { state.server = el.value; store.set('server', el.value); toast('Server: ' + el.value); renderInfo(); Object.keys(state.trying).concat(Object.keys(state.codeOpen)).forEach(function (k) { if (state.trying[k] || state.codeOpen[k]) { renderOp(k); } }); }
        else if (bind === 'file') { (state.files[id] = state.files[id] || {})[name] = el.files; renderOp(id); }
        else if (bind === 'file-item') {
            var slots = fileSlots(id, name).slice();
            slots[Number(el.getAttribute('data-index'))] = el.files && el.files[0] ? el.files[0] : null;
            (state.files[id] = state.files[id] || {})[name] = slots;
            renderOp(id);
        }
        else if (bind === 'form-item') { setListItem(id, name, Number(el.getAttribute('data-index')), el.value); }
        else if (bind === 'param') { (state.inputs[id] = state.inputs[id] || {})[name] = el.value; }
        else if (bind === 'form') { (state.forms[id] = state.forms[id] || {})[name] = el.value; }
        if (['param', 'form', 'form-item'].indexOf(bind) !== -1) { persistInputs(); }
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
            var changed = state.appearOpen || state.authMenuOpen || state.loginOpen || state.drawer || state.downloadOpen || state.envsOpen;
            var envsWereOpen = state.envsOpen;
            state.appearOpen = false; state.authMenuOpen = false; state.loginOpen = false; state.downloadOpen = false; state.envsOpen = false;
            if (changed) { setDrawer(false); renderAppear(); renderAuth(); renderLogin(); renderDownload(); renderEnvs(); }
            if (envsWereOpen) { saveEnvs(); afterEnvChange(); }
            if (e.target === els.filter && els.filter.value) { state.filter = ''; els.filter.value = ''; renderNav(); renderOps(); }
        }
    });

    document.addEventListener('mousedown', function (e) {
        var t = e.target;
        if (state.appearOpen && !(t.closest && t.closest('[data-g4t-pop="appear"]'))) { state.appearOpen = false; renderAppear(); }
        if (state.authMenuOpen && !(t.closest && t.closest('[data-g4t-pop="auth"]'))) { state.authMenuOpen = false; renderAuth(); }
        if (state.downloadOpen && !(t.closest && t.closest('[data-g4t-pop="download"]'))) { state.downloadOpen = false; renderDownload(); }
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

    loadEnvs();
    state.creds = loadJson('creds', {}) || {};
    restoreInputs();
    applyRoot();
    renderEnvButton();
    renderVersions();
    renderAppear();
    load();
})();
</script>
@endverbatim
