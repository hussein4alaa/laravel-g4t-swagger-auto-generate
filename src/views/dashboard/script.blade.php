@verbatim
<script>
(function () {
    'use strict';

    var D = window.G4T_DASHBOARD || {};
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var THEMES = ['default', 'theme-feeling-blue', 'theme-flattop', 'theme-material', 'theme-monokai', 'theme-muted', 'theme-newspaper', 'theme-outline'];
    var SCALAR_THEMES = ['default', 'alternate', 'moon', 'purple', 'solarized', 'bluePlanet', 'deepSpace', 'saturn', 'kepler', 'elysiajs', 'fastify', 'mars', 'laserwave', 'none'];
    var SECTIONS = [
        ['general', 'General', ['title', 'description', 'version', 'email']],
        ['interface', 'Interface', ['ui', 'theme', 'g4t', 'scalar']],
        ['endpoints', 'Endpoints', []],
        ['versions', 'Versions & servers', ['versions', 'default', 'servers']],
        ['security', 'Security schemes', ['security_schemes', 'auth_middlewares']],
        ['headers', 'Global headers', ['global_headers']],
        ['environments', 'Environments', ['environments']],
        ['login', 'Try it out logins', ['auto_login']],
        ['generation', 'Generation', ['infer_response_examples', 'infer_error_response_examples', 'response_schemas', 'unwrap_resource_collection_examples', 'include_web_routes', 'spatie_query_builder', 'show_prefix']],
        ['access', 'Access', ['enable', 'enable_auth', 'username', 'password']],
        ['links', 'Partner links', []]
    ];
    var FLAGS = [
        ['infer_response_examples', 'Response examples from controllers', 'response()->json, Resources, models, paginators…'],
        ['infer_error_response_examples', 'Error examples', 'abort(), json(…, 4xx), validation 422'],
        ['response_schemas', 'Response schemas', 'Resources and models become $ref components'],
        ['unwrap_resource_collection_examples', 'Unwrap collections', 'Non-paginated collections as a top-level array'],
        ['include_web_routes', 'Include web routes', 'Also document web routes that use your controllers'],
        ['spatie_query_builder', 'Spatie query builder', 'Add filter, sort and include to GET routes']
    ];
    var ICON = {
        drag: '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.6"/><circle cx="15" cy="6" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18" r="1.6"/><circle cx="15" cy="18" r="1.6"/></svg>',
        trash: '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>',
        eye: '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>',
        check: '<svg aria-hidden="true" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5L20 7"/></svg>',
        pen: '<svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></svg>',
        eyeOff: '<svg aria-hidden="true" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M10.6 5.1A10.6 10.6 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7c1.6 0 3-.4 4.3-1"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>'
    };

    var server = null, model = null, originalPayload = null, errors = {}, saving = false, focusAfterRender = null;
    // Open text editors ("op:<id>", "tag:<name>"); view state only, never saved.
    var editingTexts = {};
    // New partner link tokens made in this page and not saved yet (a new link, or New address on an old one).
    var linkTokens = {};
    /** The address a link has now: its new unsaved token, else the saved one (null when it cannot be shown). */
    function linkToken(l) { return linkTokens[l.id] || l.token || null; }
    function newToken() {
        var bytes = new Uint8Array(24);
        (window.crypto || window.msCrypto).getRandomValues(bytes);
        return btoa(String.fromCharCode.apply(null, bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function linkUrl(token) { return D.urls.docs + (D.urls.docs.indexOf('?') === -1 ? '?' : '&') + 'key=' + encodeURIComponent(token); }
    var $ = function (id) { return document.getElementById(id); };
    var els = { nav: $('d-nav'), content: $('d-content'), savebar: $('d-savebar'), toast: $('d-toast') };

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    }
    function clone(value) { return JSON.parse(JSON.stringify(value)); }
    var toastTimer = null;
    function toast(message) {
        clearTimeout(toastTimer);
        els.toast.textContent = message; els.toast.hidden = false;
        toastTimer = setTimeout(function () { els.toast.hidden = true; }, 2200);
    }

    /* ---------- model ---------- */

    function headerList(raw) {
        if (!raw) { return []; }
        var list = Array.isArray(raw) ? raw : Object.keys(raw).map(function (k) { return Object.assign({ name: k }, raw[k]); });
        return list.map(function (h) { return typeof h === 'string' ? { name: h } : (h || {}); }).map(function (h) {
            return { name: h.name || '', description: h.description || '', required: !!h.required, example: h.example == null ? '' : String(h.example), default: h.default == null ? '' : String(h.default), enumText: Array.isArray(h.enum) ? h.enum.join(', ') : '' };
        });
    }

    /** {name: value} maps (or [{key, value}] lists) as editable rows. */
    function pairList(raw) {
        if (!raw) { return []; }
        if (Array.isArray(raw)) { return raw.map(function (p) { return { key: String((p || {}).key || ''), value: String((p || {}).value == null ? '' : p.value) }; }); }
        return Object.keys(raw).map(function (k) { return { key: k, value: raw[k] == null ? '' : String(raw[k]) }; });
    }

    /** English / Arabic texts: { info: {lang: {title, description}}, tags: {tag: {lang: text}}, operations: {id: {lang: {summary, description}}} }. */
    function normalizeTexts(raw) {
        var object = function (v) { return v && typeof v === 'object' && !Array.isArray(v) ? clone(v) : {}; };
        raw = raw || {};
        return { info: object(raw.info), tags: object(raw.tags), operations: object(raw.operations) };
    }
    function pruneTexts(texts) {
        var prune = function (value) {
            if (typeof value === 'string') { return value.trim() === '' ? undefined : value; }
            if (!value || typeof value !== 'object') { return undefined; }
            var out = {}, any = false;
            Object.keys(value).forEach(function (k) { var v = prune(value[k]); if (v !== undefined) { out[k] = v; any = true; } });
            return any ? out : undefined;
        };
        return { info: prune(texts.info) || {}, tags: prune(texts.tags) || {}, operations: prune(texts.operations) || {} };
    }
    function textOf(kind, key, lang, fieldName) {
        var t = model.texts;
        if (kind === 'info') { return ((t.info[lang] || {})[fieldName]) || ''; }
        if (kind === 'tag') { return ((t.tags[key] || {})[lang]) || ''; }
        return (((t.operations[key] || {})[lang] || {})[fieldName]) || '';
    }
    function setText(kind, key, lang, fieldName, value) {
        var t = model.texts;
        if (kind === 'info') { (t.info[lang] = t.info[lang] || {})[fieldName] = value; return; }
        if (kind === 'tag') { (t.tags[key] = t.tags[key] || {})[lang] = value; return; }
        var op = t.operations[key] = t.operations[key] || {};
        (op[lang] = op[lang] || {})[fieldName] = value;
    }
    function hasTexts(kind, key) {
        var pruned = pruneTexts(model.texts);
        return kind === 'tag' ? !!pruned.tags[key] : !!pruned.operations[key] || !!pruneMeta(model.meta)[key];
    }
    var LANGUAGES = { en: { name: 'English', code: 'EN', dir: 'ltr' }, ar: { name: 'العربية', code: 'AR', dir: 'rtl' } };
    function textField(label, kind, key, lang, fieldName, placeholder, multiline) {
        var attrs = ' data-text="' + kind + '" data-key="' + esc(key) + '" data-lang="' + lang + '" data-field="' + fieldName + '" placeholder="' + esc(placeholder || '') + '" autocomplete="off"';
        var value = textOf(kind, key, lang, fieldName);
        return '<label class="d-field">' + esc(label) + (multiline ? '<textarea rows="3"' + attrs + '>' + esc(value) + '</textarea>' : '<input' + attrs + ' value="' + esc(value) + '">') + '</label>';
    }
    /** One panel per language, written in its own direction so labels and text line up. */
    function langPanel(lang, body, note) {
        var l = LANGUAGES[lang];
        return '<div class="d-lang" dir="' + l.dir + '" lang="' + lang + '"><div class="d-lang-head"><span class="d-lang-code">' + l.code + '</span><span>' + l.name + '</span>' + (note ? '<span class="d-hint">' + esc(note) + '</span>' : '') + '</div>' + body + '</div>';
    }
    /* ---------- status & note per operation ---------- */

    var STATUSES = [['stable', 'Stable'], ['new', 'New'], ['beta', 'Beta'], ['deprecated', 'Deprecated']];
    function pruneMeta(meta) {
        var out = {};
        Object.keys(meta).forEach(function (id) {
            var m = meta[id] || {}, clean = {};
            ['status', 'sunset', 'replacement', 'note'].forEach(function (k) { if (typeof m[k] === 'string' && m[k].trim() !== '') { clean[k] = m[k]; } });
            if (clean.status && clean.status !== 'deprecated') { delete clean.sunset; delete clean.replacement; }
            if (Object.keys(clean).length) { out[id] = clean; }
        });
        return out;
    }
    /** Dashboard value, else the one set in code with ->deprecated() / ->beta(). */
    function metaOf(id, key) {
        var own = (model.meta[id] || {})[key];
        if (own != null && own !== '') { return own; }
        var code = (model.info[id] || {}).code_status || {};
        if (key === 'status') { return code.status || 'stable'; }
        return (model.meta[id] && model.meta[id].status && model.meta[id].status !== 'deprecated') ? '' : (code[key] || '');
    }
    function setMeta(id, key, value) { (model.meta[id] = model.meta[id] || {})[key] = value; }
    function statusChip(id) {
        var status = metaOf(id, 'status');
        if (status === 'stable') { return ''; }
        var sunset = status === 'deprecated' ? metaOf(id, 'sunset') : '';
        return '<span class="d-status is-' + esc(status) + '">' + esc(status) + (sunset ? ' · ' + esc(sunset) : '') + '</span>';
    }
    function opMetaEditor(id) {
        var status = metaOf(id, 'status'), code = (model.info[id] || {}).code_status || {};
        var options = Object.keys(model.info).filter(function (other) { return other !== id; }).map(function (other) { var o = model.info[other]; return '<option value="' + esc(o.method + ' ' + o.path) + '">' + esc(o.summary || '') + '</option>'; }).join('');
        var html = '<div class="d-meta"><div class="d-field">Status<div class="g4t-seg" role="radiogroup" aria-label="Status" style="grid-template-columns:repeat(4,1fr);max-width:440px">' + STATUSES.map(function (st) {
            return '<button type="button" role="radio" aria-checked="' + (st[0] === status) + '" data-action="set-status" data-key="' + esc(id) + '" data-value="' + st[0] + '">' + st[1] + '</button>';
        }).join('') + '</div>' + (code.status && !(model.meta[id] || {}).status ? '<span class="d-hint">Set in code with ->' + esc(code.status) + '(); choose another status here to replace it.</span>' : '') + '</div>';
        if (status === 'deprecated') {
            html += '<div class="d-grid"><label class="d-field">Removal date (sunset)<input type="date" data-meta="sunset" data-key="' + esc(id) + '" value="' + esc(metaOf(id, 'sunset')) + '"><span class="d-hint">The docs count the days left.</span></label>' +
                '<label class="d-field">Use instead<input class="g4t-mono" list="d-ops-' + esc(id) + '" data-meta="replacement" data-key="' + esc(id) + '" value="' + esc(metaOf(id, 'replacement')) + '" placeholder="POST /api/v2/tickets" autocomplete="off" spellcheck="false"><datalist id="d-ops-' + esc(id) + '">' + options + '</datalist></label></div>';
        }
        return html + '<label class="d-field">Note for clients<textarea rows="2" dir="auto" data-meta="note" data-key="' + esc(id) + '" placeholder="e.g. Send amounts in fils, not dinars. Rate limited to 10 requests a minute.">' + esc(metaOf(id, 'note')) + '</textarea><span class="d-hint">Shown at the top of the endpoint in the docs, in any language.</span></label></div>';
    }
    function opTextsEditor(id) {
        var op = model.info[id];
        return '<div class="d-texts">' + opMetaEditor(id) + '<div class="d-lang-cols">' +
            langPanel('en', textField('Summary', 'op', id, 'en', 'summary', op.summary || 'Summary') + textField('Description', 'op', id, 'en', 'description', op.description || 'Description', true), 'empty keeps the text from your code') +
            langPanel('ar', textField('الملخص', 'op', id, 'ar', 'summary', 'ملخص قصير') + textField('الوصف', 'op', id, 'ar', 'description', 'ماذا يفعل هذا الطلب', true)) +
            '</div></div>';
    }
    function tagTextsEditor(tag) {
        var fallback = model.defaults.tags[tag] || 'Description';
        return '<div class="d-texts"><div class="d-lang-cols">' +
            langPanel('en', textField('Description', 'tag', tag, 'en', 'description', fallback, true), 'empty keeps the text from your code') +
            langPanel('ar', textField('الوصف', 'tag', tag, 'ar', 'description', 'وصف القسم', true)) + '</div></div>';
    }
    function textsButton(key, label) {
        var kind = key.indexOf('op:') === 0 ? 'op' : 'tag', open = !!editingTexts[key];
        return '<button type="button" class="d-icon-btn' + (hasTexts(kind, key.slice(kind === 'op' ? 3 : 4)) ? ' has-texts' : '') + '" data-action="edit-texts" data-value="' + esc(key) + '" aria-expanded="' + open + '" aria-label="Edit ' + esc(label) + '" title="' + (kind === 'op' ? 'Status, note and English / Arabic texts' : 'English / Arabic texts') + '">' + ICON.pen + '</button>';
    }

    function toModel(state) {
        var s = clone(state.settings || {});
        var schemes = s.security_schemes || {};
        // One login (the config file's shape) or a list; logins without a URL are off.
        var logins = Array.isArray(s.auto_login) ? s.auto_login : (s.auto_login && typeof s.auto_login === 'object' ? [s.auto_login] : []);
        var tags = [], ops = {}, info = {};
        (state.operations || []).forEach(function (o) {
            info[o.id] = o;
            // ->hiddenDoc() routes are only offered to partner links, not ordered or shown under Endpoints.
            if (o.hidden_in_code) { return; }
            if (!ops[o.tag]) { ops[o.tag] = []; tags.push(o.tag); }
            ops[o.tag].push(o.id);
        });
        var flags = {};
        FLAGS.forEach(function (f) { flags[f[0]] = !!s[f[0]]; });
        return {
            general: { title: s.title || '', description: s.description || '', version: s.version || '', email: s.email || '' },
            ui: s.ui || 'g4t', theme: s.theme || 'default',
            g4t: { mode: (s.g4t || {}).mode || 'light', density: (s.g4t || {}).density || 'comfortable', gettingStarted: (s.g4t || {}).getting_started !== false && (s.g4t || {}).getting_started !== 'false' },
            scalar: { theme: (s.scalar || {}).theme || 'purple', layout: (s.scalar || {}).layout || 'modern' },
            versions: (Array.isArray(s.versions) && s.versions.length ? s.versions : ['all']).map(String),
            def: s.default || 'all',
            servers: (s.servers || []).map(function (x) { return { url: x.url || '', description: x.description || '' }; }),
            schemes: Object.keys(schemes).map(function (name) {
                var x = schemes[name] || {};
                return { name: name, type: x.type || 'http', scheme: x.scheme || 'bearer', where: x.in || 'header', keyName: x.name || '', bearerFormat: x.bearerFormat || '' };
            }),
            authMiddlewares: (Array.isArray(s.auth_middlewares) ? s.auth_middlewares : []).map(String),
            headers: headerList(s.global_headers),
            envs: (Array.isArray(s.environments) ? s.environments : []).map(function (e) {
                return { name: e.name || '', base_url: e.base_url || '', vars: pairList(e.variables), headers: pairList(e.headers) };
            }),
            logins: logins.filter(function (l) { return l && l.url; }).map(function (l) {
                return {
                    name: l.name || '', url: l.url || '', method: l.method || 'POST', token_path: l.token_path || '', security_scheme: l.security_scheme || '',
                    fields: Object.keys(l.fields || {}).map(function (k) { return { key: k, label: l.fields[k] }; }), prefill: l.prefill || null
                };
            }),
            flags: flags,
            showPrefix: (s.show_prefix || []).join(', '),
            access: { enable: !!s.enable, enable_auth: !!s.enable_auth, username: s.username || '', password: '', passwordSet: !!s.password_set },
            links: (state.links || []).map(function (l) { return { id: l.id, name: l.name, tags: (l.tags || []).slice(), operations: (l.operations || []).slice(), token: l.token || null, security: Array.isArray(l.security) ? l.security.slice() : null, versions: Array.isArray(l.versions) ? l.versions.slice() : null, environments: Array.isArray(l.environments) ? l.environments.slice() : null, expires: l.expires || '', hash: l.hash, created: l.created, expired: !!l.expired }; }),
            meta: clone(((state.layout && state.layout.meta && state.layout.meta.operations) && !Array.isArray(state.layout.meta.operations)) ? state.layout.meta.operations : {}),
            texts: normalizeTexts(state.layout && state.layout.texts), defaults: state.defaults || { tags: {} },
            tags: tags, ops: ops, info: info, hidden: ((state.layout && state.layout.hidden) || []).slice(), hiddenTags: ((state.layout && state.layout.hidden_tags) || []).slice()
        };
    }

    function splitList(text) { return String(text || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean); }

    /** The request body: swagger.* values plus the endpoints layout. */
    function toPayload(m) {
        var schemes = {};
        m.schemes.forEach(function (x) {
            if (!x.name) { return; }
            var out = { type: x.type };
            if (x.type === 'http') { out.scheme = x.scheme; if (x.scheme === 'bearer' && x.bearerFormat) { out.bearerFormat = x.bearerFormat; } }
            if (x.type === 'apiKey') { out.in = x.where; out.name = x.keyName; }
            schemes[x.name] = out;
        });
        var settings = {
            title: m.general.title, description: m.general.description, version: m.general.version, email: m.general.email || null,
            ui: m.ui, theme: m.theme, g4t: { mode: m.g4t.mode, density: m.g4t.density, getting_started: !!m.g4t.gettingStarted }, scalar: { theme: m.scalar.theme, layout: m.scalar.layout },
            versions: m.versions.slice(), default: m.def,
            servers: m.servers.map(function (x) { return { url: x.url, description: x.description }; }),
            security_schemes: schemes,
            auth_middlewares: m.authMiddlewares.map(function (v) { return v.trim(); }),
            global_headers: m.headers.map(function (h) {
                var out = { name: h.name, required: !!h.required };
                if (h.description) { out.description = h.description; }
                if (h.example) { out.example = h.example; }
                if (h.default) { out.default = h.default; }
                if (splitList(h.enumText).length) { out.enum = splitList(h.enumText); }
                return out;
            }),
            environments: m.envs.map(function (e) {
                var pairs = function (list) { return list.map(function (p) { return { key: p.key, value: p.value }; }); };
                return { name: e.name, base_url: e.base_url, variables: pairs(e.vars), headers: pairs(e.headers) };
            }),
            auto_login: m.logins.map(function (l, i) {
                var fields = {};
                l.fields.forEach(function (f) { if (f.key) { fields[f.key] = f.label || f.key; } });
                var out = { name: l.name || 'Login ' + (i + 1), url: l.url, method: l.method, fields: fields, token_path: l.token_path || null, security_scheme: l.security_scheme || null };
                if (l.prefill) { out.prefill = l.prefill; }
                return out;
            }),
            show_prefix: splitList(m.showPrefix),
            enable: m.access.enable, enable_auth: m.access.enable_auth, username: m.access.username
        };
        FLAGS.forEach(function (f) { settings[f[0]] = !!m.flags[f[0]]; });
        if (m.access.password) { settings.password = m.access.password; }
        var operations = {};
        m.tags.forEach(function (t) { operations[t] = m.ops[t].slice(); });
        var links = m.links.map(function (l) {
            var out = { id: l.id, name: l.name, tags: l.tags.slice(), operations: l.operations.slice(), expires: l.expires || null };
            // null takes every scheme; a list keeps only the schemes that still exist.
            var keep = function (list, names) { return list === null ? null : list.filter(function (n) { return names.indexOf(n) !== -1; }); };
            out.security = keep(l.security, m.schemes.map(function (x) { return x.name; }));
            out.versions = keep(l.versions, m.versions);
            out.environments = keep(l.environments, m.envs.map(function (e) { return e.name; }));
            if (linkTokens[l.id]) { out.token = linkTokens[l.id]; } else { out.hash = l.hash; }
            return out;
        });
        return { settings: settings, links: links, layout: { tags: m.tags.slice(), operations: operations, hidden: m.hidden.slice(), hidden_tags: m.hiddenTags.slice(), texts: pruneTexts(m.texts), meta: { operations: pruneMeta(m.meta) } } };
    }

    function changedSettings(payload) {
        var out = {};
        Object.keys(payload.settings).forEach(function (key) {
            if (JSON.stringify(payload.settings[key]) !== JSON.stringify(originalPayload.settings[key])) { out[key] = payload.settings[key]; }
        });
        return out;
    }
    function isDirty() { return !!model && !!originalPayload && (resetToRouteOrder || JSON.stringify(toPayload(model)) !== JSON.stringify(originalPayload)); }

    function getPath(obj, path) { return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, obj); }
    function setPath(obj, path, value) {
        var keys = path.split('.'), last = keys.pop();
        var target = keys.reduce(function (o, k) { return o[k]; }, obj);
        target[last] = value;
    }

    /* ---------- rendering helpers ---------- */

    function errorText(key) { return errors[key] ? '<span class="d-error">' + esc([].concat(errors[key])[0]) + '</span>' : ''; }
    function field(label, path, opts) {
        opts = opts || {};
        var value = getPath(model, path), err = opts.err ? errors[opts.err] : null;
        var control;
        if (opts.type === 'textarea') {
            control = '<textarea data-bind="' + esc(path) + '" rows="' + (opts.rows || 3) + '"' + (opts.placeholder ? ' placeholder="' + esc(opts.placeholder) + '"' : '') + '>' + esc(value) + '</textarea>';
        } else if (opts.options) {
            control = '<select data-bind="' + esc(path) + '"' + (opts.rerender ? ' data-rerender' : '') + '>' + opts.options.map(function (o) {
                var v = Array.isArray(o) ? o[0] : o, l = Array.isArray(o) ? o[1] : o;
                return '<option value="' + esc(v) + '"' + (String(v) === String(value) ? ' selected' : '') + '>' + esc(l) + '</option>';
            }).join('') + '</select>';
        } else {
            control = '<input type="' + (opts.type || 'text') + '" data-bind="' + esc(path) + '" value="' + esc(value) + '"' + (opts.placeholder ? ' placeholder="' + esc(opts.placeholder) + '"' : '') + (opts.mono ? ' class="g4t-mono"' : '') + ' autocomplete="' + (opts.autocomplete || 'off') + '" spellcheck="false">';
        }
        return '<label class="d-field' + (err ? ' is-invalid' : '') + '">' + esc(label) + control + (opts.hint ? '<span class="d-hint">' + esc(opts.hint) + '</span>' : '') + (opts.err ? errorText(opts.err) : '') + '</label>';
    }
    function toggle(label, path, hint) {
        return '<label class="d-switch"><span>' + esc(label) + (hint ? '<span class="d-hint">' + esc(hint) + '</span>' : '') + '</span><input type="checkbox" data-bind="' + esc(path) + '"' + (getPath(model, path) ? ' checked' : '') + '></label>';
    }
    function seg(path, options) {
        var value = getPath(model, path);
        return '<div class="g4t-seg" role="radiogroup" style="grid-template-columns:repeat(' + options.length + ',1fr);max-width:420px">' + options.map(function (o) {
            return '<button type="button" role="radio" aria-checked="' + (o[0] === value) + '" data-action="set" data-path="' + esc(path) + '" data-value="' + esc(o[0]) + '">' + esc(o[1]) + '</button>';
        }).join('') + '</div>';
    }
    function handle(label) { return '<button type="button" class="d-drag" aria-label="Move ' + esc(label) + ' (drag, or use the arrow keys)">' + ICON.drag + '</button>'; }
    function removeBtn(list, index, label) { return '<button type="button" class="d-icon-btn is-danger" data-action="remove" data-list="' + list + '" data-index="' + index + '" aria-label="Remove ' + esc(label) + '">' + ICON.trash + '</button>'; }
    function addBtn(list, label) { return '<button type="button" class="g4t-btn g4t-add-item" data-action="add" data-list="' + list + '" style="justify-self:start">+ ' + esc(label) + '</button>'; }
    function section(id, title, description, body) {
        var keys = (SECTIONS.filter(function (s) { return s[0] === id; })[0] || [])[2] || [];
        var saved = id === 'links' ? server.links.length > 0 : id === 'endpoints'
            ? (server.layout.tags.length || server.layout.hidden.length || server.layout.hidden_tags.length || Object.keys(server.layout.operations).length || Object.keys(normalizeTexts(server.layout.texts).operations).length || Object.keys(normalizeTexts(server.layout.texts).tags).length || Object.keys(((server.layout.meta || {}).operations) || {}).length)
            : keys.some(function (k) { return server.overridden.indexOf(k) !== -1; }) || (id === 'general' && Object.keys(normalizeTexts(server.layout.texts).info).length > 0);
        pageHeader = { title: title, description: description, saved: !!saved };
        return '<section class="d-section" id="sec-' + id + '">' + body + '</section>';
    }

    /* ---------- sections ---------- */

    function sGeneral() {
        return section('general', 'General', 'The <code class="g4t-mono">info</code> block of the OpenAPI document.',
            '<div class="d-grid">' + field('Title', 'general.title', { err: 'title' }) + field('Version', 'general.version', { err: 'version', mono: true }) + field('Contact email', 'general.email', { err: 'email', type: 'email' }) + '</div>' +
            field('Description', 'general.description', { type: 'textarea', err: 'description', hint: 'Shown under the title in every UI.' }) +
            langPanel('ar', textField('العنوان', 'info', '', 'ar', 'title', 'عنوان التوثيق') + textField('الوصف', 'info', '', 'ar', 'description', 'يظهر تحت العنوان', true)) +
            '<span class="d-hint">Shown when a reader picks العربية in the g4t UI. Endpoints and sections get theirs from the pen button under Endpoints.</span>');
    }
    function sInterface() {
        var body = '<div class="d-field">Documentation UI' + seg('ui', [['g4t', 'g4t'], ['swagger', 'Swagger UI'], ['scalar', 'Scalar']]) + '<span class="d-hint">Readers can still switch for one visit with ?ui=… on the URL.</span></div>';
        if (model.ui === 'g4t') {
            body += '<div class="d-grid"><div class="d-field">Default mode' + seg('g4t.mode', [['light', 'Light'], ['dark', 'Dark'], ['system', 'System']]) + '</div><div class="d-field">Density' + seg('g4t.density', [['comfortable', 'Comfortable'], ['compact', 'Compact']]) + '</div></div>';
            body += field('Accent theme', 'theme', { options: THEMES, err: 'theme' });
            body += toggle('Getting started card', 'g4t.gettingStarted', 'Base URL, authentication, headers, error shapes and pagination, worked out from the document.');
        } else if (model.ui === 'swagger') {
            body += field('Swagger UI theme', 'theme', { options: THEMES, err: 'theme' });
        } else {
            body += '<div class="d-grid">' + field('Scalar theme', 'scalar.theme', { options: SCALAR_THEMES, err: 'scalar.theme' }) + field('Layout', 'scalar.layout', { options: [['modern', 'Modern'], ['classic', 'Classic']] }) + '</div>';
        }
        return section('interface', 'Interface', 'What readers see at the documentation URL.', body);
    }
    function sEndpoints() {
        var hiddenCount = Object.keys(model.info).filter(function (id) { return model.hidden.indexOf(id) !== -1 || model.hiddenTags.indexOf(model.info[id].tag) !== -1; }).length;
        var body = '<div class="g4t-actions" style="justify-content:space-between"><span class="g4t-muted" style="font-size:13px">' + Object.keys(model.info).length + ' operations' + (hiddenCount ? ' · ' + hiddenCount + ' hidden' : '') + (model.hiddenTags.length ? ' · ' + model.hiddenTags.length + ' controller' + (model.hiddenTags.length > 1 ? 's' : '') + ' hidden' : '') + '</span>' +
            '<button type="button" class="g4t-btn" data-action="reset-layout">Default order &amp; show all</button></div><div class="d-list" data-sortable="tags">' +
            model.tags.map(function (tag, ti) {
                var tagHidden = model.hiddenTags.indexOf(tag) !== -1;
                return '<div class="d-tag' + (tagHidden ? ' is-hidden' : '') + '" data-sort-index="' + ti + '"><div class="d-tag-head">' + handle(tag) + '<strong>' + esc(tag) + '</strong><span class="g4t-muted" style="font-size:12px">' + model.ops[tag].length + (tagHidden ? ' · hidden, new endpoints too' : '') + '</span>' +
                    '<span class="d-tag-tools">' + textsButton('tag:' + tag, tag) +
                    '<button type="button" class="d-icon-btn d-tag-eye" data-action="toggle-tag" data-value="' + esc(tag) + '" aria-pressed="' + tagHidden + '" aria-label="' + (tagHidden ? 'Show' : 'Hide') + ' every ' + esc(tag) + ' endpoint" title="' + (tagHidden ? 'Show this controller in the documentation' : 'Hide the whole controller') + '">' + (tagHidden ? ICON.eyeOff : ICON.eye) + '</button></span></div>' + (editingTexts['tag:' + tag] ? tagTextsEditor(tag) : '') +
                    '<div class="d-ops" data-sortable="ops:' + esc(tag) + '">' + model.ops[tag].map(function (id, oi) {
                        var op = model.info[id], hidden = tagHidden || model.hidden.indexOf(id) !== -1, m = op.method.toLowerCase();
                        var shownSummary = textOf('op', id, 'en', 'summary') || op.summary;
                        return '<div class="d-op' + (hidden ? ' is-hidden' : '') + (editingTexts['op:' + id] ? ' has-editor' : '') + '" data-sort-index="' + oi + '">' + handle(op.method + ' ' + op.path) +
                            '<span class="d-op-text"><span class="g4t-method m-' + esc(m) + '">' + esc(op.method) + '</span><span class="d-op-path">' + esc(op.path) + '</span><span class="d-op-summary">' + esc(shownSummary) + '</span>' + statusChip(id) + ((model.meta[id] || {}).note || (op.code_status || {}).note ? '<span class="d-note-dot" title="Has a note for clients">note</span>' : '') + '</span>' +
                            textsButton('op:' + id, op.method + ' ' + op.path) +
                            '<button type="button" class="d-icon-btn" data-action="toggle-op"' + (tagHidden ? ' disabled' : '') + ' data-value="' + esc(id) + '" aria-pressed="' + hidden + '" aria-label="' + (hidden ? 'Show' : 'Hide') + ' ' + esc(op.method + ' ' + op.path) + '" title="' + (hidden ? 'Hidden from the documentation' : 'Hide from the documentation') + '">' + (hidden ? ICON.eyeOff : ICON.eye) + '</button>' + (editingTexts['op:' + id] ? opTextsEditor(id) : '') + '</div>';
                    }).join('') + '</div></div>';
            }).join('') + '</div>';
        if (!model.tags.length) { body = '<div class="d-note">No documented operations yet. Routes need the <code class="g4t-mono">api</code> middleware.</div>'; }
        return section('endpoints', 'Endpoints', 'Drag sections and operations to set their order in every UI. The eye hides an operation from the documentation (it keeps working in your app).', body);
    }
    function sVersions() {
        var versions = '<div class="d-list" data-sortable="versions">' + model.versions.map(function (v, i) {
            return '<div class="d-item" data-sort-index="' + i + '">' + handle('version ' + v) + '<div class="d-item-body"><div class="d-row"><label class="d-field' + (errors['versions.' + i] ? ' is-invalid' : '') + '">Version<input class="g4t-mono" data-bind="versions.' + i + '" value="' + esc(v) + '" placeholder="v1" autocomplete="off" spellcheck="false">' + errorText('versions.' + i) + '</label>' +
                '<label class="d-radio" style="padding-bottom:9px"><input type="radio" name="d-default" data-action-change="default" value="' + esc(v) + '"' + (v === model.def ? ' checked' : '') + '> default</label></div></div>' + (model.versions.length > 1 ? removeBtn('versions', i, 'version ' + v) : '') + '</div>';
        }).join('') + '</div>' + addBtn('versions', 'Add version') + errorText('default') + errorText('versions');
        var servers = '<div class="d-list" data-sortable="servers">' + model.servers.map(function (sv, i) {
            return '<div class="d-item" data-sort-index="' + i + '">' + handle('server ' + sv.url) + '<div class="d-item-body"><div class="d-row">' +
                '<label class="d-field' + (errors['servers.' + i + '.url'] ? ' is-invalid' : '') + '" style="grid-column:span 2">URL<input class="g4t-mono" data-bind="servers.' + i + '.url" value="' + esc(sv.url) + '" placeholder="https://api.example.com" autocomplete="off" spellcheck="false">' + errorText('servers.' + i + '.url') + '</label>' +
                '<label class="d-field">Description<input data-bind="servers.' + i + '.description" value="' + esc(sv.description) + '" placeholder="Production" autocomplete="off"></label></div></div>' + removeBtn('servers', i, 'server') + '</div>';
        }).join('') + '</div>' + addBtn('servers', 'Add server');
        return section('versions', 'Versions & servers', 'Versions filter paths by segment (<code class="g4t-mono">v1</code> matches <code class="g4t-mono">/api/v1/…</code>). The first server is the default target of Try it out.',
            '<div class="d-field">Versions<span class="d-hint">The first one is listed first in the version switcher.</span></div>' + versions + '<div class="d-field" style="margin-top:6px">Servers</div>' + servers);
    }
    function sSecurity() {
        var list = '<div class="d-list" data-sortable="schemes">' + model.schemes.map(function (x, i) {
            var key = 'security_schemes.' + x.name;
            var row = '<div class="d-row">' + '<label class="d-field">Name<input class="g4t-mono" data-bind="schemes.' + i + '.name" value="' + esc(x.name) + '" placeholder="bearerAuth" autocomplete="off" spellcheck="false"></label>' +
                '<label class="d-field">Type<select data-bind="schemes.' + i + '.type" data-rerender>' + [['http', 'HTTP (bearer / basic)'], ['apiKey', 'API key'], ['oauth2', 'OAuth2 token'], ['openIdConnect', 'OpenID Connect token']].map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === x.type ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>';
            if (x.type === 'http') {
                row += '<label class="d-field">Scheme<select data-bind="schemes.' + i + '.scheme" data-rerender><option value="bearer"' + (x.scheme === 'bearer' ? ' selected' : '') + '>Bearer token</option><option value="basic"' + (x.scheme === 'basic' ? ' selected' : '') + '>Basic (username + password)</option></select>' + errorText(key + '.scheme') + '</label>';
                if (x.scheme === 'bearer') { row += '<label class="d-field">Bearer format<input data-bind="schemes.' + i + '.bearerFormat" value="' + esc(x.bearerFormat) + '" placeholder="JWT (optional)" autocomplete="off"></label>'; }
            } else if (x.type === 'apiKey') {
                row += '<label class="d-field">Sent in<select data-bind="schemes.' + i + '.where"><option value="header"' + (x.where === 'header' ? ' selected' : '') + '>Header</option><option value="query"' + (x.where === 'query' ? ' selected' : '') + '>Query string</option><option value="cookie"' + (x.where === 'cookie' ? ' selected' : '') + '>Cookie</option></select></label>' +
                    '<label class="d-field' + (errors[key + '.name'] ? ' is-invalid' : '') + '">Header / parameter name<input class="g4t-mono" data-bind="schemes.' + i + '.keyName" value="' + esc(x.keyName) + '" placeholder="X-API-Key" autocomplete="off" spellcheck="false">' + errorText(key + '.name') + '</label>';
            }
            return '<div class="d-item" data-sort-index="' + i + '">' + handle('scheme ' + x.name) + '<div class="d-item-body">' + row + '</div></div>' + removeBtn('schemes', i, 'scheme ' + x.name) + '</div>';
        }).join('') + '</div>';
        var middlewares = '<div class="d-list">' + model.authMiddlewares.map(function (name, i) {
            return '<div class="d-item"><div class="d-item-body"><label class="d-field' + (errors['auth_middlewares.' + i] ? ' is-invalid' : '') + '"><span class="g4t-sr">Middleware ' + (i + 1) + '</span><input class="g4t-mono" data-bind="authMiddlewares.' + i + '" value="' + esc(name) + '" placeholder="jwt.auth" aria-label="Middleware name" autocomplete="off" spellcheck="false">' + errorText('auth_middlewares.' + i) + '</label></div>' + removeBtn('authMiddlewares', i, 'middleware ' + name) + '</div>';
        }).join('') + '</div>';
        return section('security', 'Security schemes', 'How protected routes are authorized. Protected routes accept any one of these; the g4t UI shows one field per scheme in Authorize.',
            (model.schemes.length ? list : '<div class="d-note">No schemes: protected routes will not ask for credentials.</div>') + addBtn('schemes', 'Add scheme') + errorText('security_schemes') +
            '<div class="d-field" style="margin-top:6px">Auth middlewares<span class="d-hint">A route using one of these is documented as protected (security + 401). <code class="g4t-mono">auth</code> and <code class="g4t-mono">auth:*</code> always count; add your own, e.g. <code class="g4t-mono">jwt.auth</code> or <code class="g4t-mono">auth.admin</code>.</span></div>' +
            (model.authMiddlewares.length ? middlewares : '') + addBtn('authMiddlewares', 'Add middleware') + errorText('auth_middlewares'));
    }
    function sHeaders() {
        var list = '<div class="d-list" data-sortable="headers">' + model.headers.map(function (h, i) {
            return '<div class="d-item" data-sort-index="' + i + '">' + handle('header ' + h.name) + '<div class="d-item-body"><div class="d-row">' +
                '<label class="d-field' + (errors['global_headers.' + i + '.name'] ? ' is-invalid' : '') + '">Header<input class="g4t-mono" data-bind="headers.' + i + '.name" value="' + esc(h.name) + '" placeholder="Accept-Language" autocomplete="off" spellcheck="false">' + errorText('global_headers.' + i + '.name') + '</label>' +
                '<label class="d-field">Description<input data-bind="headers.' + i + '.description" value="' + esc(h.description) + '" placeholder="Response language" autocomplete="off"></label>' +
                '<label class="d-field">Example<input class="g4t-mono" data-bind="headers.' + i + '.example" value="' + esc(h.example) + '" autocomplete="off"></label>' +
                '<label class="d-field">Default<input class="g4t-mono" data-bind="headers.' + i + '.default" value="' + esc(h.default) + '" autocomplete="off"></label>' +
                '<label class="d-field" style="grid-column:span 2">Allowed values<input class="g4t-mono" data-bind="headers.' + i + '.enumText" value="' + esc(h.enumText) + '" placeholder="en, ar (optional)" autocomplete="off"></label>' +
                '<label class="d-radio" style="padding-bottom:9px"><input type="checkbox" data-bind="headers.' + i + '.required"' + (h.required ? ' checked' : '') + '> required</label></div></div>' + removeBtn('headers', i, 'header ' + h.name) + '</div>';
        }).join('') + '</div>';
        return section('headers', 'Global headers', 'Header parameters added to every operation, so every UI shows an input for them (a language, a tenant, a request id…).',
            (model.headers.length ? list : '<div class="d-note">No global headers.</div>') + addBtn('headers', 'Add header'));
    }
    function sEnvironments() {
        var pairRows = function (env, ei, kind, keyLabel, keyHolder, valueHolder) {
            var errKey = 'environments.' + ei + '.' + (kind === 'vars' ? 'variables' : 'headers');
            return '<div class="d-list" data-sortable="env-' + kind + ':' + ei + '">' + env[kind].map(function (p, i) {
                return '<div class="d-item" data-sort-index="' + i + '">' + handle(keyLabel + ' ' + p.key) + '<div class="d-item-body"><div class="d-row">' +
                    '<label class="d-field' + (errors[errKey + '.' + i + '.key'] ? ' is-invalid' : '') + '">' + keyLabel + '<input class="g4t-mono" data-bind="envs.' + ei + '.' + kind + '.' + i + '.key" value="' + esc(p.key) + '" placeholder="' + keyHolder + '" autocomplete="off" spellcheck="false">' + errorText(errKey + '.' + i + '.key') + '</label>' +
                    '<label class="d-field" style="grid-column:span 2">Value<input class="g4t-mono" data-bind="envs.' + ei + '.' + kind + '.' + i + '.value" value="' + esc(p.value) + '" placeholder="' + valueHolder + '" autocomplete="off" spellcheck="false"></label></div></div>' +
                    removeBtn('env-' + kind + ':' + ei, i, keyLabel + ' ' + p.key) + '</div>';
            }).join('') + '</div>';
        };
        var list = '<div class="d-list" data-sortable="envs">' + model.envs.map(function (env, ei) {
            var key = 'environments.' + ei;
            return '<div class="d-item d-env" data-sort-index="' + ei + '">' + handle('environment ' + env.name) + '<div class="d-item-body">' +
                '<div class="d-row"><label class="d-field' + (errors[key + '.name'] ? ' is-invalid' : '') + '">Name<input data-bind="envs.' + ei + '.name" value="' + esc(env.name) + '" placeholder="Staging" autocomplete="off">' + errorText(key + '.name') + '</label>' +
                '<label class="d-field' + (errors[key + '.base_url'] ? ' is-invalid' : '') + '" style="grid-column:span 2">Base URL<input class="g4t-mono" data-bind="envs.' + ei + '.base_url" value="' + esc(env.base_url) + '" placeholder="https://staging.example.com (empty: the page\'s server)" autocomplete="off" spellcheck="false">' + errorText(key + '.base_url') + '</label></div>' +
                '<div class="d-field">Variables<span class="d-hint">Used as {{name}} in any parameter, field, header or body.</span></div>' +
                pairRows(env, ei, 'vars', 'Variable', 'tenant', 'acme') + addBtn('env-vars:' + ei, 'Add variable') +
                '<div class="d-field">Headers<span class="d-hint">Sent with every request in this environment; values may use {{variables}}.</span></div>' +
                pairRows(env, ei, 'headers', 'Header', 'X-Tenant', '{{tenant}}') + addBtn('env-headers:' + ei, 'Add header') +
                '</div>' + removeBtn('envs', ei, 'environment ' + env.name) + '</div>';
        }).join('') + '</div>';
        return section('environments', 'Environments', 'Shared environments every reader can switch between in the g4t UI (the environment menu in the header). Readers can still add their own. Values are visible in the page, so never put secrets here.',
            (model.envs.length ? list : '<div class="d-note">No shared environments: readers only see the ones they create.</div>') + addBtn('envs', 'Add environment') + errorText('environments'));
    }
    function sLogin() {
        var schemeOptions = [['', '— first bearer scheme —']].concat(model.schemes.filter(function (x) { return x.name; }).map(function (x) { return [x.name, x.name]; }));
        var list = '<div class="d-list" data-sortable="logins">' + model.logins.map(function (login, li) {
            var key = 'auto_login.' + li;
            var fields = '<div class="d-list" data-sortable="login-fields:' + li + '">' + login.fields.map(function (f, i) {
                return '<div class="d-item" data-sort-index="' + i + '">' + handle('field ' + f.key) + '<div class="d-item-body"><div class="d-row">' +
                    '<label class="d-field">Request field<input class="g4t-mono" data-bind="logins.' + li + '.fields.' + i + '.key" value="' + esc(f.key) + '" placeholder="email" autocomplete="off" spellcheck="false"></label>' +
                    '<label class="d-field">Label<input data-bind="logins.' + li + '.fields.' + i + '.label" value="' + esc(f.label) + '" placeholder="Email" autocomplete="off"></label></div></div>' + removeBtn('login-fields:' + li, i, 'field ' + f.key) + '</div>';
            }).join('') + '</div>' + errorText(key + '.fields');
            return '<div class="d-item" data-sort-index="' + li + '">' + handle('login ' + login.name) + '<div class="d-item-body">' +
                '<div class="d-grid">' + field('Name', 'logins.' + li + '.name', { placeholder: li ? 'Admin' : 'Customer', err: key + '.name', hint: 'Shown in the Log in dialog when there are several.' }) +
                field('Login endpoint', 'logins.' + li + '.url', { placeholder: '/api/auth/login', mono: true, err: key + '.url' }) +
                field('Method', 'logins.' + li + '.method', { options: ['POST', 'PUT'] }) +
                field('Token path in the response', 'logins.' + li + '.token_path', { placeholder: 'access_token or data.token', mono: true, hint: 'Empty: token, access_token, data.token… are tried.' }) +
                field('Security scheme it fills', 'logins.' + li + '.security_scheme', { options: schemeOptions, hint: 'Give each login its own scheme to stay signed in to all of them at once.' }) + '</div>' +
                '<div class="d-field">Form fields</div>' + fields + addBtn('login-fields:' + li, 'Add field') + '</div>' + removeBtn('logins', li, 'login ' + login.name) + '</div>';
        }).join('') + '</div>';
        return section('login', 'Try it out logins', 'Log in buttons that post these fields to your login endpoints and use the token they return. With several (customer, admin…) the reader picks one in the Log in dialog. Without any, readers can still paste a token.',
            (model.logins.length ? list : '<div class="d-note">No login: readers paste a token in Authorize.</div>') + addBtn('logins', 'Add login'));
    }
    function sGeneration() {
        return section('generation', 'Generation', 'What the generator reads from your code.',
            '<div class="d-list">' + FLAGS.map(function (f) { return toggle(f[1], 'flags.' + f[0], f[2]); }).join('') + '</div>' +
            field('Only document these route prefixes', 'showPrefix', { placeholder: 'api/v1, api/admin (empty: all)', mono: true, hint: 'Comma separated, as returned by $route->getPrefix().' }));
    }
    function sAccess() {
        var a = model.access;
        return section('access', 'Access', 'Who can open the documentation. Changes here apply on the next request.',
            toggle('Serve the documentation', 'access.enable', 'Off: the docs URL answers 403. Off in production unless enabled.') +
            toggle('Basic Auth on the documentation', 'access.enable_auth', 'Readers sign in with the username and password below.') +
            (a.enable_auth ? '<div class="d-grid">' + field('Docs username', 'access.username', { err: 'username', autocomplete: 'off' }) +
                field('Docs password', 'access.password', { type: 'password', err: 'password', autocomplete: 'new-password', placeholder: a.passwordSet ? '•••••••• (unchanged)' : 'Set a password', hint: a.passwordSet ? 'Leave empty to keep the current password.' : 'An empty password never authenticates.' }) + '</div>' : '') +
            '<div class="d-note">The dashboard itself is protected by <code class="g4t-mono">SWAGGER_DASHBOARD_USERNAME</code> / <code class="g4t-mono">SWAGGER_DASHBOARD_PASSWORD</code> in your <code class="g4t-mono">.env</code>; it cannot change them.</div>');
    }

    /* Endpoint picker of a partner link: search, pick single endpoints, hidden ones included. */
    var linkSearch = {};
    function opHiddenLabel(o) {
        if (o.hidden_in_code) { return 'hidden in code'; }
        if (model.hidden.indexOf(o.id) !== -1 || model.hiddenTags.indexOf(o.tag) !== -1) { return 'hidden in the docs'; }
        return '';
    }
    function linkOpsHtml(i) {
        var l = model.links[i], q = (linkSearch[l.id] || '').trim().toLowerCase(), words = q.split(/\s+/).filter(Boolean);
        var all = Object.keys(model.info).map(function (id) { return model.info[id]; });
        var rows = all.filter(function (o) {
            var hay = (o.method + ' ' + o.path + ' ' + (o.summary || '') + ' ' + o.tag + ' ' + opHiddenLabel(o)).toLowerCase();
            return words.every(function (w) { return hay.indexOf(w) !== -1; });
        }).sort(function (a, b) { return (l.operations.indexOf(b.id) !== -1) - (l.operations.indexOf(a.id) !== -1); });
        if (!rows.length) { return '<div class="d-hint" style="padding:10px">No endpoint matches “' + esc(q) + '”.</div>'; }
        return rows.map(function (o) {
            var picked = l.operations.indexOf(o.id) !== -1, viaSection = !picked && l.tags.indexOf(o.tag) !== -1 && !opHiddenLabel(o), hiddenLabel = opHiddenLabel(o);
            return '<button type="button" class="d-pick' + (picked ? ' is-picked' : '') + '" role="checkbox" aria-checked="' + picked + '" data-action="link-op" data-index="' + i + '" data-value="' + esc(o.id) + '">' +
                '<span class="d-pick-box" aria-hidden="true">' + (picked ? '✓' : viaSection ? '•' : '') + '</span><span class="g4t-method m-' + esc(o.method.toLowerCase()) + '">' + esc(o.method) + '</span>' +
                '<span class="d-pick-text"><span class="g4t-mono d-pick-path">' + esc(o.path) + '</span><span class="d-pick-meta">' + esc(o.summary || '') + ' · ' + esc(o.tag) + '</span></span>' +
                (hiddenLabel ? '<span class="d-status is-deprecated d-pick-hidden">' + esc(hiddenLabel) + '</span>' : viaSection ? '<span class="d-hint">in a picked section</span>' : '') + '</button>';
        }).join('');
    }
    function renderLinkOps(i) {
        var box = document.getElementById('d-link-ops-' + model.links[i].id);
        if (box) { box.innerHTML = linkOpsHtml(i); }
    }
    function linkScopeText(l) {
        var hidden = l.operations.filter(function (id) { return model.info[id] && opHiddenLabel(model.info[id]); }).length;
        if (!l.tags.length && !l.operations.length) { return 'Nothing picked: every visible section and endpoint.'; }
        return [l.tags.length ? l.tags.length + ' section' + (l.tags.length > 1 ? 's' : '') : '', l.operations.length ? l.operations.length + ' endpoint' + (l.operations.length > 1 ? 's' : '') + (hidden ? ' (' + hidden + ' hidden from the docs)' : '') : ''].filter(Boolean).join(' + ') + '. Schemas used only by the rest are left out.';
    }

    // Whole-section picker state per link: search text and "picked only".
    var secSearch = {}, secPickedOnly = {};
    function sectionInfo(tag) {
        var tagHidden = model.hiddenTags.indexOf(tag) !== -1, all = model.ops[tag] || [];
        // A section shares its visible endpoints; hidden ones are shared only when picked one by one.
        var visible = tagHidden ? [] : all.filter(function (id) { return model.hidden.indexOf(id) === -1; });
        var methods = {}, order = [];
        visible.forEach(function (id) { var m = model.info[id].method; if (!methods[m]) { methods[m] = 0; order.push(m); } methods[m]++; });
        return { tag: tag, hidden: tagHidden, visible: visible.length, total: all.length, methods: methods, order: order, desc: textOf('tag', tag, 'en', 'description') || model.defaults.tags[tag] || '' };
    }
    /** The sections the search and "picked only" leave on screen. */
    function shownSections(l) {
        var words = (secSearch[l.id] || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
        return model.tags.filter(function (tag) {
            if (secPickedOnly[l.id] && l.tags.indexOf(tag) === -1) { return false; }
            var hay = (tag + ' ' + (model.defaults.tags[tag] || '')).toLowerCase();
            return words.every(function (w) { return hay.indexOf(w) !== -1; });
        });
    }
    function linkSecRowsHtml(i) {
        var l = model.links[i], shown = shownSections(l);
        if (!shown.length) { return '<div class="d-sec-empty">' + (secPickedOnly[l.id] && !l.tags.length ? 'No section picked yet.' : 'No section matches.') + '</div>'; }
        return shown.map(function (tag) {
            var info = sectionInfo(tag), on = l.tags.indexOf(tag) !== -1;
            var count = info.hidden ? '<span class="d-sec-warn" title="Hidden in the docs: a section shares nothing while it is hidden">hidden</span>'
                : info.visible + (info.total > info.visible ? '<span class="d-sec-of">/' + info.total + '</span>' : '');
            return '<button type="button" class="d-sec" role="checkbox" aria-checked="' + on + '" data-action="link-tag" data-index="' + i + '" data-value="' + esc(tag) + '" title="' + esc(tag + (info.desc ? ' · ' + info.desc : '')) + '">' +
                '<span class="d-sec-box">' + (on ? ICON.check : '') + '</span>' +
                '<span class="d-sec-text"><span class="d-sec-name">' + esc(tag) + '</span>' + (info.desc ? '<span class="d-sec-desc" dir="auto">' + esc(info.desc) + '</span>' : '') + '</span>' +
                '<span class="d-sec-methods">' + info.order.map(function (m) { return '<span class="g4t-method m-' + esc(m.toLowerCase()) + '" title="' + info.methods[m] + ' ' + esc(m) + '">' + esc(m) + '<b>' + info.methods[m] + '</b></span>'; }).join('') + '</span>' +
                '<span class="d-sec-count">' + count + '</span></button>';
        }).join('');
    }
    function linkSecSummary(l) {
        var shared = 0;
        l.tags.forEach(function (tag) { shared += sectionInfo(tag).visible; });
        return l.tags.length ? l.tags.length + ' of ' + model.tags.length + ' section' + (model.tags.length === 1 ? '' : 's') + ' · ' + shared + ' endpoint' + (shared === 1 ? '' : 's') : 'No section picked';
    }
    function linkSecHeadHtml(i) {
        var l = model.links[i], filtered = (secSearch[l.id] || '').trim() !== '' || secPickedOnly[l.id];
        var shown = shownSections(l), allShownPicked = shown.length > 0 && shown.every(function (t) { return l.tags.indexOf(t) !== -1; });
        return '<span class="d-sec-summary">' + linkSecSummary(l) + '</span>' +
            '<span class="d-sec-tools"><button type="button" class="g4t-link-btn" data-action="link-tags-all" data-index="' + i + '"' + (allShownPicked || !shown.length ? ' disabled' : '') + '>' + (filtered ? 'Select shown' : 'Select all') + '</button>' +
            '<button type="button" class="g4t-link-btn" data-action="link-tags-none" data-index="' + i + '"' + (l.tags.length ? '' : ' disabled') + '>Clear</button></span>';
    }
    /*
     * What a link's docs show of a list (security schemes, versions, shared environments): Default (all of them),
     * only the picked ones, or, where allowed, none. null is Default, a list the picked names, [] none.
     */
    var linkChoiceModes = {};
    var LINK_CHOICES = {
        security: { label: 'Security schemes', page: 'security', pageTitle: 'Security schemes', empty: 'No schemes yet',
            names: function () { return model.schemes.map(function (x) { return x.name; }).filter(Boolean); },
            pickedHint: function (p) { return 'Protected endpoints ask for ' + (p.length === 1 ? p[0] : 'any one of ' + p.join(', ')) + '; the other schemes and their Try it out logins are left out.'; },
            defaultHint: function (n) { return 'Every scheme of the docs: ' + n.join(', ') + '.'; } },
        versions: { label: 'Versions', page: 'versions', pageTitle: 'Versions & servers', empty: 'No versions',
            names: function () { return model.versions.filter(Boolean); },
            pickedHint: function (p) { return 'Opens ' + p.join(', ') + (p.indexOf(model.def) !== -1 ? ', starting on ' + model.def : ', starting on ' + p[0]) + '. Other versions stay closed.'; },
            defaultHint: function (n) { return 'Every version (' + n.join(', ') + '), starting on ' + model.def + '.'; } },
        environments: { label: 'Shared environments', page: 'environments', pageTitle: 'Environments', empty: 'No shared environments yet', none: true,
            names: function () { return model.envs.map(function (e) { return e.name; }).filter(Boolean); },
            pickedHint: function (p) { return 'Shows ' + p.join(', ') + '. Readers can still add their own.'; },
            defaultHint: function (n) { return 'Every shared environment: ' + n.join(', ') + '.'; },
            noneHint: 'No shared environment (their base URLs and values stay private). Readers can still add their own.' }
    };
    function linkChoiceMode(l, kind) {
        var value = l[kind];
        if (value === null || value === undefined) { return 'default'; }
        return value.length || linkChoiceModes[l.id + ':' + kind] === 'custom' || !LINK_CHOICES[kind].none ? 'custom' : 'none';
    }
    function linkChoiceHtml(i, l, kind) {
        var c = LINK_CHOICES[kind], names = c.names(), key = 'links.' + i + '.' + kind, mode = linkChoiceMode(l, kind);
        if (!names.length) {
            return '<div class="d-field">' + c.label + '<span class="d-hint">' + c.empty + ': add them under <a href="' + esc(pageUrl(c.page)) + '" data-action="page" data-value="' + c.page + '">' + c.pageTitle + '</a>.</span></div>';
        }
        var picked = mode === 'custom' ? l[kind].filter(function (n) { return names.indexOf(n) !== -1; }) : [];
        var modes = [['default', 'Default'], ['custom', 'Pick']].concat(c.none ? [['none', 'None']] : []);
        var hint = mode === 'default' ? c.defaultHint(names) : mode === 'none' ? c.noneHint : picked.length ? c.pickedHint(picked) : (c.none ? 'Nothing picked: the same as None.' : 'Pick at least one.');
        var invalid = Object.keys(errors).some(function (k) { return k === key || k.indexOf(key + '.') === 0; });
        return '<div class="d-field d-link-choice' + (invalid ? ' is-invalid' : '') + '">' + c.label +
            '<div class="g4t-seg" role="radiogroup" aria-label="' + esc(c.label) + '" style="grid-template-columns:repeat(' + modes.length + ',1fr);max-width:' + (modes.length * 120) + 'px">' + modes.map(function (m) {
                return '<button type="button" role="radio" aria-checked="' + (m[0] === mode) + '" data-action="link-choice-mode" data-index="' + i + '" data-key="' + kind + '" data-value="' + m[0] + '">' + m[1] + '</button>';
            }).join('') + '</div>' +
            (mode === 'custom' ? '<div class="d-chips" role="group" aria-label="' + esc(c.label) + ' of this link">' + names.map(function (n) {
                return '<button type="button" class="d-chip" aria-pressed="' + (picked.indexOf(n) !== -1) + '" data-action="link-choice" data-index="' + i + '" data-key="' + kind + '" data-value="' + esc(n) + '"><span class="g4t-mono">' + esc(n) + '</span></button>';
            }).join('') + '</div>' : '') +
            '<span class="d-hint">' + esc(hint) + '</span>' +
            Object.keys(errors).filter(function (k) { return k === key || k.indexOf(key + '.') === 0; }).map(errorText).join('') + '</div>';
    }
    function linkChoicesHtml(i, l) {
        return '<div class="d-link-choices">' + linkChoiceHtml(i, l, 'versions') + linkChoiceHtml(i, l, 'environments') + linkChoiceHtml(i, l, 'security') + '</div>';
    }
    /** Whole sections a link shares: a compact, searchable list that stays short with many sections. */
    function linkSectionsHtml(i, l) {
        return '<div class="d-field">Whole sections' +
            '<div class="d-sec-head" id="d-link-sec-head-' + esc(l.id) + '">' + linkSecHeadHtml(i) + '</div>' +
            (model.tags.length > 6
                ? '<div class="d-sec-filter"><div class="d-search"><input type="search" data-sec-search="' + i + '" value="' + esc(secSearch[l.id] || '') + '" placeholder="Filter ' + model.tags.length + ' sections" autocomplete="off" spellcheck="false" aria-label="Filter sections"></div>' +
                  '<button type="button" class="g4t-btn d-sec-only" data-action="link-tags-only" data-index="' + i + '" aria-pressed="' + !!secPickedOnly[l.id] + '">Picked only</button></div>'
                : '') +
            '<div class="d-sec-list" id="d-link-secs-' + esc(l.id) + '" role="group" aria-label="Whole sections">' + linkSecRowsHtml(i) + '</div>' +
            '<span class="d-hint">Every visible endpoint of a picked section, including endpoints added to it later. The number is the endpoints it shares' + '.</span></div>';
    }
    function sLinks() {
        var list = '<div class="d-list">' + model.links.map(function (l, i) {
            var token = linkToken(l), replacing = !!(linkTokens[l.id] && l.hash);
            var address = '<div class="d-field">Address' +
                (token
                    ? '<div class="d-link-url"><input class="g4t-mono" readonly value="' + esc(linkUrl(token)) + '" aria-label="Link address" onfocus="this.select()">' +
                      '<button type="button" class="g4t-btn g4t-btn-primary" data-action="link-copy" data-value="' + esc(l.id) + '">Copy</button>' +
                      (l.hash ? (replacing
                          ? '<button type="button" class="g4t-btn" data-action="link-keep-address" data-value="' + esc(l.id) + '">Keep old address</button>'
                          : '<button type="button" class="g4t-btn" data-action="link-regenerate" data-value="' + esc(l.id) + '">New address</button>') : '') + '</div>'
                    : '<div class="d-link-url"><span class="d-hint">This link was made before addresses were kept, so its address cannot be shown. Make a new one to see it here.</span><button type="button" class="g4t-btn" data-action="link-regenerate" data-value="' + esc(l.id) + '">New address</button></div>') +
                (!l.hash ? '<span class="d-hint">Save to activate it.</span>'
                    : replacing ? '<span class="d-hint d-link-warn">New address: the old one stops working when you save.</span>'
                    : token ? '<span class="d-hint">New address makes another one and the current one stops working when you save, for example when it leaked.</span>' : '') +
                '</div>';
            return '<div class="d-item"><div class="d-item-body">' +
                '<div class="d-row" style="align-items:start"><label class="d-field' + (errors['links.' + i + '.name'] ? ' is-invalid' : '') + '">Shared with<input data-bind="links.' + i + '.name" value="' + esc(l.name) + '" placeholder="Acme mobile team" autocomplete="off">' + errorText('links.' + i + '.name') + '</label>' +
                '<label class="d-field">Expires<input type="date" data-bind="links.' + i + '.expires" value="' + esc(l.expires) + '"><span class="d-hint">' + (l.expired ? '<b style="color:var(--err-fg)">Expired</b> · ' : '') + (l.created ? 'created ' + esc(l.created) : 'not saved yet') + (l.expires ? '' : ' · never expires') + '</span></label></div>' +
                address + errorText('links.' + i + '.token') +
                linkSectionsHtml(i, l) +
                '<div class="d-field">Single endpoints' + (l.operations.length ? ' <span class="d-badge">' + l.operations.length + ' picked</span>' : '') +
                '<div class="d-search"><input type="search" data-link-search="' + i + '" value="' + esc(linkSearch[l.id] || '') + '" placeholder="Search by path, method, summary or section" autocomplete="off" spellcheck="false" aria-label="Search endpoints"></div>' +
                '<div class="d-picker" id="d-link-ops-' + esc(l.id) + '" role="group" aria-label="Endpoints">' + linkOpsHtml(i) + '</div>' +
                '<span class="d-hint">Picked endpoints show even when they are hidden from the docs (the eye under Endpoints, or ->hiddenDoc() in code).</span></div>' +
                linkChoicesHtml(i, l) +
                '<div class="d-note d-link-scope">' + esc(linkScopeText(l)) + '</div>' +
                '</div>' + removeBtn('links', i, 'link ' + l.name) + '</div>';
        }).join('') + '</div>';
        return section('links', 'Partner links', 'A secret address per partner or client: it opens the g4t docs without the docs password (even when the docs are turned off) and shows only the sections and endpoints you pick, hidden ones included. Remove a link to revoke it.',
            (model.links.length ? list : '<div class="d-note">No partner links.</div>') + addBtn('links', 'New link'));
    }

    /* ---------- pages ---------- */

    // Each page lives at {dashboard}/{id}; unsaved changes stay in memory while you move between them.
    var PAGES = [
        { id: 'overview', title: 'Overview', group: '', render: sOverview },
        { id: 'general', title: 'General', group: 'Documentation', section: 'general', render: sGeneral },
        { id: 'interface', title: 'Interface', group: 'Documentation', section: 'interface', render: sInterface },
        { id: 'endpoints', title: 'Endpoints', group: 'Documentation', section: 'endpoints', render: sEndpoints },
        { id: 'versions', title: 'Versions & servers', group: 'API', section: 'versions', render: sVersions },
        { id: 'security', title: 'Security schemes', group: 'API', section: 'security', render: sSecurity },
        { id: 'headers', title: 'Global headers', group: 'API', section: 'headers', render: sHeaders },
        { id: 'environments', title: 'Environments', group: 'API', section: 'environments', render: sEnvironments },
        { id: 'logins', title: 'Try it out logins', group: 'Try it out', section: 'login', render: sLogin },
        { id: 'generation', title: 'Generation', group: 'Generation', section: 'generation', render: sGeneration },
        { id: 'access', title: 'Access', group: 'Access', section: 'access', render: sAccess },
        { id: 'partner-links', title: 'Partner links', group: 'Access', section: 'links', render: sLinks }
    ];
    var pageHeader = null;
    var currentPage = PAGES.some(function (p) { return p.id === D.page; }) ? D.page : 'overview';
    function pageById(id) { return PAGES.filter(function (p) { return p.id === id; })[0] || PAGES[0]; }
    function pageUrl(id) { return D.urls.base + (id === 'overview' ? '' : '/' + id); }
    /** The page that edits a setting (or an error's field): "auto_login.0.url" → logins. */
    function pageOfKey(key) {
        var root = String(key).split('.')[0];
        if (root === 'links') { return 'partner-links'; }
        var section = SECTIONS.filter(function (s) { return s[2].indexOf(root) !== -1; })[0];
        var page = section && PAGES.filter(function (p) { return p.section === section[0]; })[0];
        return page ? page.id : 'overview';
    }
    /** Pages with unsaved changes, for the dots in the navigation. */
    function dirtyPages() {
        var out = {};
        if (!model || !originalPayload) { return out; }
        var payload = toPayload(model);
        Object.keys(changedSettings(payload)).forEach(function (key) { out[pageOfKey(key)] = true; });
        var layout = payload.layout, before = originalPayload.layout;
        if (resetToRouteOrder || JSON.stringify([layout.tags, layout.operations, layout.hidden, layout.hidden_tags, layout.meta, layout.texts.tags, layout.texts.operations]) !== JSON.stringify([before.tags, before.operations, before.hidden, before.hidden_tags, before.meta, before.texts.tags, before.texts.operations])) { out.endpoints = true; }
        if (JSON.stringify(layout.texts.info) !== JSON.stringify(before.texts.info)) { out.general = true; }
        if (JSON.stringify(payload.links) !== JSON.stringify(originalPayload.links)) { out['partner-links'] = true; }
        return out;
    }
    function goToPage(id, push) {
        currentPage = pageById(id).id;
        if (push) { try { history.pushState({ page: currentPage }, '', pageUrl(currentPage)); } catch (e) { } }
        renderNav();
        render();
        window.scrollTo(0, 0);
        var current = els.nav.querySelector('[aria-current="page"]');
        if (current && current.scrollIntoView) { current.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
        var heading = document.getElementById('d-page-title');
        if (heading) { heading.focus({ preventScroll: true }); }
    }
    window.addEventListener('popstate', function (e) {
        var id = (e.state && e.state.page) || (location.pathname.replace(/\/+$/, '').split('/').pop());
        goToPage(PAGES.some(function (p) { return p.id === id; }) ? id : 'overview', false);
    });

    function statCard(page, title, value, lines) {
        return '<a class="d-stat" href="' + esc(pageUrl(page)) + '" data-action="page" data-value="' + page + '"><span class="d-stat-title">' + esc(title) + '</span><span class="d-stat-value">' + value + '</span>' +
            (lines.length ? '<span class="d-stat-lines">' + lines.map(function (l) { return '<span>' + l + '</span>'; }).join('') + '</span>' : '') + '</a>';
    }
    function sOverview() {
        var ops = Object.keys(model.info);
        var hidden = ops.filter(function (id) { return model.hidden.indexOf(id) !== -1 || model.hiddenTags.indexOf(model.info[id].tag) !== -1; }).length;
        var statuses = { 'new': 0, beta: 0, deprecated: 0 };
        ops.forEach(function (id) { var st = metaOf(id, 'status'); if (statuses[st] !== undefined) { statuses[st]++; } });
        var expired = (server.links || []).filter(function (l) { return l.expired; }).length;
        var a = model.access, uiNames = { g4t: 'g4t', swagger: 'Swagger UI', scalar: 'Scalar' };
        var cards = '<div class="d-stats">' +
            statCard('access', 'Documentation', a.enable ? '<span class="d-on">On</span>' : '<span class="d-off">Off</span>', [a.enable_auth ? 'Basic Auth required' : 'No password', 'Shown with ' + esc(uiNames[model.ui] || model.ui)]) +
            statCard('endpoints', 'Endpoints', String(ops.length), [hidden + ' hidden', statuses.deprecated + ' deprecated · ' + statuses.beta + ' beta · ' + statuses['new'] + ' new']) +
            statCard('security', 'Security', String(model.schemes.length) + ' scheme' + (model.schemes.length === 1 ? '' : 's'), [model.logins.length + ' Try it out login' + (model.logins.length === 1 ? '' : 's'), model.headers.length + ' global header' + (model.headers.length === 1 ? '' : 's')]) +
            statCard('partner-links', 'Partner links', String(model.links.length), [expired ? expired + ' expired' : 'None expired', model.envs.length + ' shared environment' + (model.envs.length === 1 ? '' : 's')]) +
            '</div>';
        var saved = {};
        server.overridden.forEach(function (key) { (saved[pageOfKey(key)] = saved[pageOfKey(key)] || []).push(key); });
        if (server.layout.tags.length || server.layout.hidden.length || server.layout.hidden_tags.length || Object.keys(server.layout.operations || {}).length) { (saved.endpoints = saved.endpoints || []).push('order and visibility'); }
        var savedList = Object.keys(saved).length
            ? '<div class="d-saved">' + PAGES.filter(function (p) { return saved[p.id]; }).map(function (p) {
                return '<a href="' + esc(pageUrl(p.id)) + '" data-action="page" data-value="' + p.id + '"><b>' + esc(p.title) + '</b><span class="g4t-mono">' + saved[p.id].map(esc).join(', ') + '</span></a>';
            }).join('') + '</div><div><button type="button" class="g4t-btn g4t-btn-danger" data-action="reset-all">Remove all dashboard changes</button></div>'
            : '<div class="d-note">Nothing is saved in the dashboard yet: every value follows <code class="g4t-mono">config/swagger.php</code>.</div>';
        pageHeader = { title: 'Overview', description: 'Settings saved here are layered over <code class="g4t-mono">config/swagger.php</code>: anything you never change keeps following the file.', saved: false };
        return cards +
            '<section class="d-section"><header><h2>Saved in the dashboard</h2><p>Stored in <code class="g4t-mono">' + esc(server.settings_file) + '</code>' + (server.cached_spec ? '; saving also clears the cached document (<code class="g4t-mono">swagger:cache</code>)' : '') + '.</p></header>' + savedList + '</section>';
    }

    function render() {
        if (!model) { return; }
        var scrollY = window.scrollY;
        // Long pickers keep their scroll position when a row is toggled.
        var listScroll = {};
        Array.prototype.forEach.call(document.querySelectorAll('.d-sec-list[id], .d-picker[id]'), function (el) { listScroll[el.id] = el.scrollTop; });
        var focusedSearch = document.activeElement && document.activeElement.getAttribute && document.activeElement.getAttribute('data-sec-search');
        var page = pageById(currentPage), index = PAGES.indexOf(page);
        var body = page.render();
        var header = pageHeader || { title: page.title, description: '', saved: false };
        // Errors can sit on other pages (a save sends every page's changes): list them with links.
        var errorKeys = Object.keys(errors);
        var errorHtml = errorKeys.length ? '<div class="d-alert" role="alert"><strong>Some values need fixing:</strong>' + errorKeys.slice(0, 10).map(function (k) {
            var where = pageOfKey(k);
            return '<span>' + esc([].concat(errors[k])[0]) + (where !== currentPage ? ' <a href="' + esc(pageUrl(where)) + '" data-action="page" data-value="' + where + '">' + esc(pageById(where).title) + ' →</a>' : '') + '</span>';
        }).join('') + '</div>' : '';
        var prev = PAGES[index - 1], next = PAGES[index + 1];
        els.content.innerHTML =
            '<div class="d-page-head">' + (page.group ? '<span class="d-crumb">' + esc(page.group) + '</span>' : '') +
            '<h1 id="d-page-title" tabindex="-1">' + esc(header.title) + (header.saved ? '<span class="d-badge" title="Overrides config/swagger.php">saved in dashboard</span>' : '') + '</h1>' +
            (header.description ? '<p>' + header.description + '</p>' : '') + '</div>' +
            errorHtml + body +
            '<nav class="d-pager" aria-label="Pages">' +
            (prev ? '<a href="' + esc(pageUrl(prev.id)) + '" data-action="page" data-value="' + prev.id + '"><span>Previous</span>← ' + esc(prev.title) + '</a>' : '<span></span>') +
            (next ? '<a class="is-next" href="' + esc(pageUrl(next.id)) + '" data-action="page" data-value="' + next.id + '"><span>Next</span>' + esc(next.title) + ' →</a>' : '<span></span>') + '</nav>';
        pageHeader = null;
        window.scrollTo(0, scrollY);
        Object.keys(listScroll).forEach(function (id) { var el = document.getElementById(id); if (el) { el.scrollTop = listScroll[id]; } });
        if (focusedSearch !== null && focusedSearch !== undefined) { var again = document.querySelector('[data-sec-search="' + focusedSearch + '"]'); if (again) { again.focus(); again.setSelectionRange(again.value.length, again.value.length); } }
        renderNav();
        renderSavebar();
        if (focusAfterRender) {
            var target = document.querySelector('[data-sortable="' + CSS.escape(focusAfterRender.list) + '"] > [data-sort-index="' + focusAfterRender.index + '"] .d-drag');
            if (target) { target.focus(); }
            focusAfterRender = null;
        }
    }
    function renderNavDots() {
        var dirty = dirtyPages();
        Array.prototype.forEach.call(els.nav.querySelectorAll('a[data-value]'), function (a) {
            var dot = a.querySelector('.d-dot'), want = !!dirty[a.getAttribute('data-value')];
            if (want && !dot) { a.insertAdjacentHTML('beforeend', '<span class="d-dot" title="Unsaved changes"></span>'); }
            if (!want && dot) { dot.remove(); }
        });
    }
    function renderNav() {
        var dirty = dirtyPages(), group = null, html = '';
        PAGES.forEach(function (p) {
            if (p.group !== group) { html += p.group ? '<div class="d-nav-group">' + esc(p.group) + '</div>' : ''; group = p.group; }
            html += '<a href="' + esc(pageUrl(p.id)) + '" data-action="page" data-value="' + p.id + '"' + (p.id === currentPage ? ' aria-current="page"' : '') + '><span>' + esc(p.title) + '</span>' +
                (dirty[p.id] ? '<span class="d-dot" title="Unsaved changes"></span>' : '') + '</a>';
        });
        els.nav.innerHTML = html;
    }
    function renderSavebar() {
        var dirty = isDirty();
        if (model) { renderNavDots(); }
        els.savebar.hidden = !dirty && !saving;
        if (els.savebar.hidden) { return; }
        els.savebar.innerHTML = '<div class="d-savebar-inner"><span>' + (saving ? 'Saving…' : 'You have unsaved changes') + '</span>' +
            '<button type="button" class="g4t-btn d-discard" data-action="discard"' + (saving ? ' disabled' : '') + '>Discard</button>' +
            '<button type="button" class="g4t-btn g4t-btn-primary" data-action="save"' + (saving ? ' disabled' : '') + '>' + (saving ? '<span class="g4t-spinner" aria-hidden="true"></span>' : '') + 'Save changes</button></div>';
    }

    /* ---------- lists ---------- */

    function listArray(name) {
        if (name === 'versions') { return model.versions; }
        if (name === 'servers') { return model.servers; }
        if (name === 'schemes') { return model.schemes; }
        if (name === 'headers') { return model.headers; }
        if (name === 'authMiddlewares') { return model.authMiddlewares; }
        if (name === 'logins') { return model.logins; }
        if (name === 'links') { return model.links; }
        if (name.indexOf('login-fields:') === 0) { return (model.logins[Number(name.slice(13))] || {}).fields; }
        if (name === 'envs') { return model.envs; }
        if (name.indexOf('env-vars:') === 0) { return (model.envs[Number(name.slice(9))] || {}).vars; }
        if (name.indexOf('env-headers:') === 0) { return (model.envs[Number(name.slice(12))] || {}).headers; }
        if (name === 'tags') { return model.tags; }
        if (name.indexOf('ops:') === 0) { return model.ops[name.slice(4)]; }
        return null;
    }
    function move(name, from, to) {
        var list = listArray(name);
        if (!list || to < 0 || to >= list.length || from === to) { return false; }
        list.splice(to, 0, list.splice(from, 1)[0]);
        return true;
    }
    function addItem(name) {
        if (name === 'versions') { var n = 'v' + (model.versions.length); model.versions.push(model.versions.indexOf(n) === -1 ? n : 'v' + Date.now() % 1000); }
        if (name === 'servers') { model.servers.push({ url: '', description: '' }); }
        if (name === 'schemes') { model.schemes.push({ name: 'scheme' + (model.schemes.length + 1), type: 'apiKey', scheme: 'bearer', where: 'header', keyName: 'X-API-Key', bearerFormat: '' }); }
        if (name === 'headers') { model.headers.push({ name: '', description: '', required: false, example: '', default: '', enumText: '' }); }
        if (name === 'links') { var linkId = 'l' + Date.now().toString(36); linkTokens[linkId] = newToken(); model.links.push({ id: linkId, name: 'Partner ' + (model.links.length + 1), tags: [], operations: [], token: null, security: null, versions: null, environments: null, expires: '', hash: null, created: null, expired: false }); }
        if (name === 'logins') { model.logins.push({ name: model.logins.length ? 'Login ' + (model.logins.length + 1) : 'Customer', url: '', method: 'POST', token_path: '', security_scheme: '', fields: [{ key: 'email', label: 'Email' }, { key: 'password', label: 'Password' }], prefill: null }); }
        if (name.indexOf('login-fields:') === 0) { var loginFields = listArray(name); if (loginFields) { loginFields.push({ key: '', label: '' }); } }
        if (name === 'authMiddlewares') { model.authMiddlewares.push(''); }
        if (name === 'envs') { model.envs.push({ name: model.envs.length ? 'Environment ' + (model.envs.length + 1) : 'Staging', base_url: '', vars: [], headers: [] }); }
        if (name.indexOf('env-') === 0 && name !== 'envs') { var rows = listArray(name); if (rows) { rows.push({ key: '', value: '' }); } }
    }

    /* Pointer-based drag and drop: works with mouse, touch and pen; arrow keys move the focused handle. */
    var drag = null;
    function clearIndicators() { Array.prototype.forEach.call(document.querySelectorAll('.drop-before, .drop-after'), function (el) { el.classList.remove('drop-before', 'drop-after'); }); }
    document.addEventListener('pointerdown', function (e) {
        var grip = e.target.closest('.d-drag');
        if (!grip || e.button > 0) { return; }
        var item = grip.closest('[data-sort-index]'), container = item && item.parentElement;
        if (!container || !container.hasAttribute('data-sortable')) { return; }
        e.preventDefault();
        grip.setPointerCapture(e.pointerId);
        drag = { grip: grip, item: item, container: container, list: container.getAttribute('data-sortable'), from: Number(item.getAttribute('data-sort-index')), to: null };
        item.classList.add('is-dragging');
    });
    document.addEventListener('pointermove', function (e) {
        if (!drag) { return; }
        clearIndicators();
        var under = document.elementFromPoint(e.clientX, e.clientY);
        var over = under && under.closest('[data-sort-index]');
        while (over && over.parentElement !== drag.container) { over = over.parentElement && over.parentElement.closest('[data-sort-index]'); }
        if (over && over !== drag.item) {
            var r = over.getBoundingClientRect(), after = e.clientY > r.top + r.height / 2, index = Number(over.getAttribute('data-sort-index'));
            over.classList.add(after ? 'drop-after' : 'drop-before');
            drag.to = after ? index + 1 : index;
        } else {
            drag.to = null;
        }
        if (e.clientY < 70) { window.scrollBy(0, -12); } else if (e.clientY > window.innerHeight - 70) { window.scrollBy(0, 12); }
    });
    function endDrag(apply) {
        if (!drag) { return; }
        var d = drag; drag = null;
        clearIndicators();
        d.item.classList.remove('is-dragging');
        if (apply && d.to !== null) {
            var to = d.to > d.from ? d.to - 1 : d.to;
            if (move(d.list, d.from, to)) { render(); }
        }
    }
    document.addEventListener('pointerup', function () { endDrag(true); });
    document.addEventListener('pointercancel', function () { endDrag(false); });
    document.addEventListener('keydown', function (e) {
        var grip = e.target.closest && e.target.closest('.d-drag');
        if (grip && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
            var item = grip.closest('[data-sort-index]'), list = item.parentElement.getAttribute('data-sortable'), from = Number(item.getAttribute('data-sort-index'));
            var to = from + (e.key === 'ArrowUp' ? -1 : 1);
            if (move(list, from, to)) { e.preventDefault(); focusAfterRender = { list: list, index: to }; render(); }
        }
    });

    /* ---------- events ---------- */

    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-action]');
        if (!el || el.closest('form[method="POST"]') && el.type === 'submit') { return; }
        var action = el.getAttribute('data-action'), value = el.getAttribute('data-value'), list = el.getAttribute('data-list'), index = Number(el.getAttribute('data-index'));
        switch (action) {
            case 'page':
                // Plain links still work with a modifier key (new tab).
                if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) { return; }
                e.preventDefault(); goToPage(value, true); break;
            case 'set': setPath(model, el.getAttribute('data-path'), value); render(); break;
            case 'add': addItem(list); render(); break;
            case 'remove':
                var removed = listArray(list).splice(index, 1)[0];
                if (list === 'versions' && removed === model.def) { model.def = model.versions[0]; }
                render(); break;
            case 'toggle-op':
                var at = model.hidden.indexOf(value);
                if (at === -1) { model.hidden.push(value); } else { model.hidden.splice(at, 1); }
                render(); break;
            case 'link-tags-all':
                // With a filter on, only the sections on screen are added.
                var linkForAll = model.links[index];
                shownSections(linkForAll).forEach(function (t) { if (linkForAll.tags.indexOf(t) === -1) { linkForAll.tags.push(t); } });
                render(); break;
            case 'link-tags-only': secPickedOnly[model.links[index].id] = !secPickedOnly[model.links[index].id]; render(); break;
            case 'link-choice-mode':
                var choiceLink = model.links[index], choiceKind = el.getAttribute('data-key');
                linkChoiceModes[choiceLink.id + ':' + choiceKind] = value;
                if (value === 'default') { choiceLink[choiceKind] = null; }
                else if (value === 'none') { choiceLink[choiceKind] = []; }
                else if (!choiceLink[choiceKind] || !choiceLink[choiceKind].length) { choiceLink[choiceKind] = LINK_CHOICES[choiceKind].names(); }
                render(); break;
            case 'link-choice':
                var pickLink = model.links[index], pickKind = el.getAttribute('data-key'), pickList = pickLink[pickKind] || [], at4 = pickList.indexOf(value);
                if (at4 === -1) { pickList.push(value); } else { pickList.splice(at4, 1); }
                pickLink[pickKind] = pickList;
                linkChoiceModes[pickLink.id + ':' + pickKind] = 'custom';
                render(); break;
            case 'link-tags-none': model.links[index].tags = []; render(); break;
            case 'link-tag':
                var linkTags = model.links[index].tags, at2 = linkTags.indexOf(value);
                if (at2 === -1) { linkTags.push(value); } else { linkTags.splice(at2, 1); }
                render(); break;
            case 'link-op':
                var linkOps = model.links[index].operations, at3 = linkOps.indexOf(value);
                if (at3 === -1) { linkOps.push(value); } else { linkOps.splice(at3, 1); }
                // Re-render keeping the scroll position of the list.
                var pickerBox = el.closest('.d-picker'), pickerScroll = pickerBox ? pickerBox.scrollTop : 0;
                render();
                var again = document.getElementById('d-link-ops-' + model.links[index].id);
                if (again) { again.scrollTop = pickerScroll; }
                break;
            case 'link-copy':
                var copyToken = model.links.filter(function (l) { return l.id === value; }).map(linkToken)[0];
                if (copyToken && navigator.clipboard) { navigator.clipboard.writeText(linkUrl(copyToken)).then(function () { toast('Link copied'); }, function () { toast('Copy it from the field'); }); }
                break;
            case 'link-regenerate':
                linkTokens[value] = newToken();
                render(); toast('New address · save to replace the old one'); break;
            case 'link-keep-address': delete linkTokens[value]; render(); break;
            case 'set-status':
                setMeta(el.getAttribute('data-key'), 'status', value);
                render(); break;
            case 'edit-texts':
                editingTexts[value] = !editingTexts[value];
                render();
                if (editingTexts[value]) { var first = document.querySelector('[data-text][data-key="' + CSS.escape(value.replace(/^(op|tag):/, '')) + '"]'); if (first) { first.focus(); } }
                break;
            case 'toggle-tag':
                var tagAt = model.hiddenTags.indexOf(value);
                if (tagAt === -1) { model.hiddenTags.push(value); } else { model.hiddenTags.splice(tagAt, 1); }
                render(); break;
            case 'reset-layout':
                model.hidden = []; model.hiddenTags = [];
                model.tags = model.tags.slice().sort(function (a, b) { return originalOrder.tags.indexOf(a) - originalOrder.tags.indexOf(b); });
                resetToRouteOrder = true; toast('Default order and visibility apply after saving'); render(); break;
            case 'discard': model = toModel(server); errors = {}; render(); toast('Changes discarded'); break;
            case 'save': save(); break;
            case 'reset-all':
                if (window.confirm('Remove every change saved from the dashboard? config/swagger.php applies again, and partner links stop working.')) { request('DELETE').then(function (state) { if (state) { load(state); toast(state.message || 'Dashboard changes removed'); } }); }
                break;
        }
    });
    document.addEventListener('input', function (e) {
        if (model && e.target.hasAttribute && e.target.hasAttribute('data-sec-search')) {
            var si = Number(e.target.getAttribute('data-sec-search')), sl = model.links[si];
            secSearch[sl.id] = e.target.value;
            var rows = document.getElementById('d-link-secs-' + sl.id);
            if (rows) { rows.innerHTML = linkSecRowsHtml(si); }
            var head = document.getElementById('d-link-sec-head-' + sl.id);
            if (head) { head.innerHTML = linkSecHeadHtml(si); }
            return;
        }
        if (model && e.target.hasAttribute && e.target.hasAttribute('data-link-search')) {
            var li = Number(e.target.getAttribute('data-link-search'));
            linkSearch[model.links[li].id] = e.target.value;
            renderLinkOps(li);
            return;
        }
        if (model && e.target.hasAttribute && e.target.hasAttribute('data-meta')) {
            setMeta(e.target.getAttribute('data-key'), e.target.getAttribute('data-meta'), e.target.value);
            if (e.target.getAttribute('data-meta') === 'sunset') { render(); return; }
            renderSavebar();
            return;
        }
        if (model && e.target.hasAttribute && e.target.hasAttribute('data-text')) {
            var t = e.target;
            setText(t.getAttribute('data-text'), t.getAttribute('data-key'), t.getAttribute('data-lang'), t.getAttribute('data-field'), t.value);
            renderSavebar();
            return;
        }
        var path = e.target.getAttribute && e.target.getAttribute('data-bind');
        if (!path || !model) { return; }
        var oldValue = getPath(model, path);
        setPath(model, path, e.target.type === 'checkbox' ? e.target.checked : e.target.value);
        if (path.indexOf('versions.') === 0 && oldValue === model.def) { model.def = e.target.value; }
        // A renamed scheme, version or environment stays picked by the partner links (and logins) that use it.
        var renamed = /^schemes\.\d+\.name$/.test(path) ? 'security' : /^versions\.\d+$/.test(path) ? 'versions' : /^envs\.\d+\.name$/.test(path) ? 'environments' : null;
        if (renamed && oldValue) {
            model.links.forEach(function (l) { if (l[renamed]) { l[renamed] = l[renamed].map(function (n) { return n === oldValue ? e.target.value : n; }); } });
            if (renamed === 'security') { model.logins.forEach(function (lg) { if (lg.security_scheme === oldValue) { lg.security_scheme = e.target.value; } }); }
        }
        if (e.target.type === 'checkbox' && (path === 'access.enable_auth')) { render(); return; }
        renderSavebar();
    });
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el.getAttribute('data-action-change') === 'default') { model.def = el.value; renderSavebar(); return; }
        if (el.hasAttribute('data-rerender')) { render(); }
    });
    window.addEventListener('beforeunload', function (e) { if (isDirty()) { e.preventDefault(); e.returnValue = ''; } });

    /* ---------- server ---------- */

    var originalOrder = { tags: [] }, resetToRouteOrder = false;
    function request(method, body) {
        return fetch(D.urls.settings, {
            method: method, credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (json) {
                if (response.status === 401 || response.status === 419) { toast('Your session ended. Sign in again.'); setTimeout(function () { window.location.reload(); }, 900); return null; }
                if (response.status === 422) { errors = json.errors || {}; render(); toast('Fix the highlighted values'); return null; }
                if (!response.ok) { toast(json.message || ('Request failed (HTTP ' + response.status + ')')); return null; }
                return json;
            });
        }, function () { toast('Network error: the settings were not saved'); return null; });
    }
    function load(state) {
        server = state;
        model = toModel(state);
        originalPayload = toPayload(model);
        originalOrder = { tags: model.tags.slice() };
        resetToRouteOrder = false;
        errors = {};
        render();
    }
    function save() {
        if (saving) { return; }
        var payload = toPayload(model);
        if (resetToRouteOrder) { payload.layout = { tags: [], operations: {}, hidden: [], hidden_tags: [], texts: payload.layout.texts, meta: payload.layout.meta }; }
        saving = true; errors = {}; renderSavebar();
        request('PUT', { settings: changedSettings(payload), layout: payload.layout, links: payload.links }).then(function (state) {
            saving = false;
            if (state) { load(state); toast('Saved · the documentation uses the new settings'); } else { renderSavebar(); }
        });
    }

    try { history.replaceState({ page: currentPage }, '', location.href); } catch (e) { }
    renderNav();
    request('GET').then(function (state) { if (state) { load(state); } });
})();
</script>
@endverbatim
