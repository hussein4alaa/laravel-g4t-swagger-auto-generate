@verbatim
    /* ---------- local validation: the documented rules, checked before a request is sent ---------- */

    var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    function hasVariable(value) { return typeof value === 'string' && value.indexOf('{{') !== -1; }
    /** Checks one value against its schema; form values arrive as strings, so they are compared as text. */
    function checkValue(raw, value, path, out, fromForm, depth) {
        var s = resolve(raw).schema, type = schemaType(s);
        if (value === null || value === undefined || hasVariable(value)) { return; }
        var text = typeof value === 'string';
        if (s.enum && s.enum.map(String).indexOf(String(value)) === -1) { out.push({ path: path, message: 'must be one of ' + s.enum.join(', ') }); return; }
        if (type === 'integer' || type === 'number') {
            var num = text && fromForm ? Number(value) : value;
            if (typeof num !== 'number' || isNaN(num) || (text && value.trim() === '')) { out.push({ path: path, message: 'must be a number' }); return; }
            if (type === 'integer' && Math.floor(num) !== num) { out.push({ path: path, message: 'must be a whole number' }); return; }
            if (s.minimum != null && num < s.minimum) { out.push({ path: path, message: 'must be at least ' + s.minimum }); }
            if (s.maximum != null && num > s.maximum) { out.push({ path: path, message: 'must be at most ' + s.maximum }); }
        } else if (type === 'boolean') {
            if (fromForm ? ['1', '0', 'true', 'false', 'on', 'off'].indexOf(String(value).toLowerCase()) === -1 : typeof value !== 'boolean') { out.push({ path: path, message: 'must be true or false' }); }
        } else if (type === 'string' && !isBinary(raw)) {
            if (!text) { out.push({ path: path, message: 'must be text' }); return; }
            if (s.minLength != null && value.length < s.minLength) { out.push({ path: path, message: 'needs at least ' + s.minLength + ' characters' }); }
            if (s.maxLength != null && value.length > s.maxLength) { out.push({ path: path, message: 'can have at most ' + s.maxLength + ' characters (has ' + value.length + ')' }); }
            if (s.format === 'email' && !EMAIL_RE.test(value)) { out.push({ path: path, message: 'must be an email address' }); }
            if (s.format === 'date' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) { out.push({ path: path, message: 'must be a date (YYYY-MM-DD)' }); }
        } else if (type === 'array' && !fromForm) {
            if (!Array.isArray(value)) { out.push({ path: path, message: 'must be a list' }); return; }
            if (s.minItems != null && value.length < s.minItems) { out.push({ path: path, message: 'needs at least ' + s.minItems + ' item' + (s.minItems > 1 ? 's' : '') }); }
            if (s.maxItems != null && value.length > s.maxItems) { out.push({ path: path, message: 'can have at most ' + s.maxItems + ' items' }); }
            if (s.items && depth < 6) { value.forEach(function (item, i) { checkValue(s.items, item, path + '.' + i, out, false, depth + 1); }); }
        } else if (type === 'object' && !fromForm) {
            if (typeof value !== 'object' || Array.isArray(value)) { out.push({ path: path, message: 'must be an object' }); return; }
            checkObject(s, value, path, out, depth + 1);
        }
    }
    function checkObject(s, value, prefix, out, depth) {
        (s.required || []).forEach(function (key) {
            var v = value[key];
            if (v === undefined || v === null || v === '') { out.push({ path: prefix ? prefix + '.' + key : key, message: 'is required' }); }
        });
        if (depth > 6) { return; }
        Object.keys(s.properties || {}).forEach(function (key) {
            if (value[key] !== undefined) { checkValue(s.properties[key], value[key], prefix ? prefix + '.' + key : key, out, false, depth); }
        });
    }
    /** Problems the server would very likely answer 422 for; empty when the request looks valid. */
    function validateRequest(op) {
        var out = [];
        op.params.forEach(function (p) {
            if (p.in !== 'query' && p.in !== 'header') { return; }
            var value = paramValue(op, p);
            if (value === '') { if (p.required) { out.push({ path: p.name, message: 'is required' }); } return; }
            checkValue(p.schema || {}, value, p.name, out, true, 0);
        });
        if (!op.body || ['GET', 'HEAD'].indexOf(op.method) !== -1) { return out; }
        if (isMultipart(op)) {
            formFields(op).forEach(function (f) {
                if (f.binary) {
                    var files = f.multiple ? fileSlots(op.id, f.name).filter(Boolean) : Array.prototype.slice.call((state.files[op.id] || {})[f.name] || []);
                    if (f.req && !files.length) { out.push({ path: f.name, message: 'needs a file' }); }
                    return;
                }
                var values = f.multiple ? listValues(op, f).filter(function (v) { return String(v).trim() !== ''; }) : (f.value !== '' ? [f.value] : []);
                if (!values.length) { if (f.req) { out.push({ path: f.name, message: 'is required' }); } return; }
                var item = f.multiple ? (resolve(f.schema).schema.items || {}) : f.schema;
                values.forEach(function (v) { checkValue(item, String(v), f.name, out, true, 0); });
            });
            return out;
        }
        var json;
        try { json = JSON.parse(subst(bodyText(op))); } catch (e) { return out; }
        checkValue(bodySchema(op), json, '', out, false, 0);
        return out.map(function (p) { return p.path ? p : { path: 'body', message: p.message }; });
    }
    function problemsHtml(op) {
        var problems = state.problems[op.id];
        if (!problems || !problems.length) { return ''; }
        return '<div class="g4t-problems" role="alert"><div class="g4t-problems-head">' + ICON.alert + '<b>' + problems.length + ' problem' + (problems.length > 1 ? 's' : '') + ' found before sending</b><span class="g4t-muted">from the documented rules; the server has the last word</span></div><ul>' +
            problems.slice(0, 12).map(function (p) { return '<li><code class="g4t-mono">' + esc(p.path) + '</code> ' + esc(p.message) + '</li>'; }).join('') + (problems.length > 12 ? '<li>…</li>' : '') + '</ul>' +
            '<div class="g4t-actions"><button type="button" class="g4t-btn" data-action="execute-anyway" data-op="' + esc(op.id) + '">Send anyway</button><button type="button" class="g4t-link-btn" data-action="dismiss-problems" data-op="' + esc(op.id) + '">Dismiss</button></div></div>';
    }
    ACTIONS['execute-anyway'] = function (el, e, op) { if (op) { execute(op, { skipValidation: true }); } };
    ACTIONS['dismiss-problems'] = function (el, e, op) { if (op) { state.problems[op.id] = null; renderOp(op.id); } };

    /* ---------- error cases: invalid requests sent on purpose ---------- */

    function errorCases(op) {
        var cases = [];
        if (op.secured) { cases.push({ label: 'Without credentials', expect: 401, variant: { noAuth: true } }); }
        op.params.filter(function (p) { return p.in === 'path'; }).forEach(function (p) {
            var numeric = ['integer', 'number'].indexOf(schemaType(resolve(p.schema || {}).schema)) !== -1;
            cases.push({ label: 'Unknown ' + p.name, expect: 404, variant: { params: (function () { var o = {}; o[p.name] = numeric ? '999999999' : 'does-not-exist-0'; return o; })() } });
        });
        if (op.body && ['GET', 'HEAD'].indexOf(op.method) === -1) {
            var s = resolve(bodySchema(op)).schema, props = s.properties || {}, required = s.required || [];
            var set = function (name, value) { var o = {}; o[name] = value; return o; };
            if (required.length) { cases.push({ label: 'Empty body', expect: 422, variant: { emptyBody: true } }); }
            required.slice(0, 6).forEach(function (name) { cases.push({ label: 'Missing ' + name, expect: 422, variant: { omit: name } }); });
            Object.keys(props).forEach(function (name) {
                var ps = resolve(props[name]).schema, type = schemaType(ps);
                if (isBinary(props[name])) { return; }
                if (ps.enum) { cases.push({ label: name + ' not in the list', expect: 422, variant: { set: set(name, '__invalid__') } }); }
                else if (ps.maxLength != null) { cases.push({ label: name + ' too long', expect: 422, variant: { set: set(name, new Array(ps.maxLength + 2).join('x')) } }); }
                else if (type === 'integer' || type === 'number') { cases.push({ label: name + ' not a number', expect: 422, variant: { set: set(name, 'abc') } }); }
                else if (ps.format === 'email') { cases.push({ label: name + ' not an email', expect: 422, variant: { set: set(name, 'not-an-email') } }); }
            });
        }
        return cases.slice(0, 16);
    }
    function casesHtml(op) {
        var cases = errorCases(op), results = state.caseResults[op.id] || {};
        if (!cases.length) { return '<div class="g4t-history-empty">No error cases for this endpoint: it has no credentials, path parameters or body rules to break.</div>'; }
        var writes = ['GET', 'HEAD', 'OPTIONS'].indexOf(op.method) === -1;
        return '<div class="g4t-cases"><div class="g4t-cases-head"><span>Each case sends a real request with one thing wrong and compares the status.' + (writes ? ' <b>A case the server accepts changes data.</b>' : '') + '</span>' +
            '<button type="button" class="g4t-btn" data-action="run-cases" data-op="' + esc(op.id) + '">Run all</button></div>' +
            cases.map(function (c, i) {
                var r = results[i], pass = r && r.code === c.expect;
                return '<div class="g4t-case"><span class="g4t-case-label">' + esc(c.label) + '</span><span class="g4t-muted g4t-case-expect">expects ' + c.expect + '</span>' +
                    (r ? (r.busy ? '<span class="g4t-case-result">' + spinner() + '</span>' : '<button type="button" class="g4t-case-result" data-action="case-view" data-op="' + esc(op.id) + '" data-value="' + i + '" title="Show the response"><span class="g4t-status ' + statusClass(r.code) + '">' + (r.code || '—') + '</span><span class="g4t-case-mark ' + (pass ? 'is-pass' : 'is-fail') + '">' + (pass ? '✓' : '✕') + '</span><span class="g4t-muted">' + r.ms + ' ms</span></button>') : '<span></span>') +
                    '<button type="button" class="g4t-btn" data-action="run-case" data-op="' + esc(op.id) + '" data-value="' + i + '">Run</button>' +
                    (r && r.open ? '<pre class="g4t-code g4t-case-body">' + (r.json !== undefined ? highlight(pretty(r.json)) : esc(r.raw || '')) + '</pre>' : '') + '</div>';
            }).join('') + '</div>';
    }
    function runCase(op, i) {
        var c = errorCases(op)[i];
        if (!c) { return Promise.resolve(); }
        var req = buildRequest(op, c.variant);
        var results = state.caseResults[op.id] = state.caseResults[op.id] || {};
        if (req.error) { results[i] = { code: 0, ms: 0, raw: req.error }; renderOp(op.id); return Promise.resolve(); }
        results[i] = { busy: true };
        renderOp(op.id);
        var started = performance.now();
        return fetch(req.url, { method: req.method, headers: req.headers, body: req.body })
            .then(function (response) { return response.text().then(function (text) { var json; try { json = text ? JSON.parse(text) : undefined; } catch (e) { } results[i] = { code: response.status, ms: Math.round(performance.now() - started), json: json, raw: text }; }); })
            .catch(function (error) { results[i] = { code: 0, ms: Math.round(performance.now() - started), raw: error.message }; })
            .then(function () { renderOp(op.id); });
    }
    ACTIONS['toggle-cases'] = function (el, e, op) { if (op) { state.casesOpen[op.id] = !state.casesOpen[op.id]; renderOp(op.id); } };
    ACTIONS['run-case'] = function (el, e, op, value) { if (op) { runCase(op, Number(value)); } };
    ACTIONS['run-cases'] = function (el, e, op) {
        if (!op) { return; }
        var count = errorCases(op).length, i = 0;
        var next = function () { if (i < count) { runCase(op, i++).then(next); } else { toast('Error cases finished'); } };
        next();
    };
    ACTIONS['case-view'] = function (el, e, op, value) {
        var r = op && (state.caseResults[op.id] || {})[Number(value)];
        if (r) { r.open = !r.open; renderOp(op.id); }
    };
@endverbatim
