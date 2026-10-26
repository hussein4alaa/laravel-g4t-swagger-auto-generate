@verbatim
    /* ---------- webhooks: requests the API sends (x-webhooks), with a test send from this browser ---------- */

    var webhookCache = { spec: null, list: [] };
    state.whOpen = {}; state.whView = {}; state.whBodies = {}; state.whHeaders = {}; state.whBusy = {}; state.whResults = {};
    state.whUrl = store.get('webhookUrl', '');

    /** Webhooks of the loaded document: { name, id, method, summary, desc, headers, schema, example }. */
    function webhookList() {
        if (!state.spec) { return []; }
        if (webhookCache.spec === state.spec) { return webhookCache.list; }
        var all = state.spec['x-webhooks'] && typeof state.spec['x-webhooks'] === 'object' ? state.spec['x-webhooks'] : {}, used = {};
        webhookCache = { spec: state.spec, list: Object.keys(all).map(function (name) {
            var item = all[name] || {}, method = METHODS.filter(function (m) { return item[m]; })[0] || 'post', o = item[method] || {};
            var id = 'wh-' + slug(name);
            while (used[id]) { id += '_'; }
            used[id] = true;
            var json = ((o.requestBody || {}).content || {})['application/json'] || {};
            return {
                name: name, id: id, method: method.toUpperCase(), summary: o.summary || '', desc: o.description || '',
                headers: (o.parameters || []).filter(function (p) { return p && p.in === 'header'; }),
                schema: json.schema || null, example: json.example !== undefined ? json.example : json.schema ? sample(json.schema) : null
            };
        }) };
        return webhookCache.list;
    }
    function webhookByName(name) { return webhookList().filter(function (w) { return w.name === name; })[0] || null; }
    function webhookMatches(w, q) {
        var hay = (w.name + ' ' + w.summary + ' ' + w.desc).toLowerCase();
        return !q || q.split(/\s+/).every(function (word) { return hay.indexOf(word) !== -1; });
    }
    function webhookBody(w) { return state.whBodies[w.name] != null ? state.whBodies[w.name] : (w.example !== null ? pretty(w.example) : '{}'); }
    function webhookHeader(w, h) {
        var own = (state.whHeaders[w.name] || {})[h.name];
        return own != null ? own : String(h.example != null ? h.example : '');
    }
    function webhookTarget() { return subst(String(state.whUrl || '').trim()); }
    function webhookCurl(w) {
        var lines = ['curl -X ' + w.method + ' ' + sq(webhookTarget() || 'https://your-app.example/webhooks'), '-H ' + sq('Content-Type: application/json')];
        w.headers.forEach(function (h) { lines.push('-H ' + sq(h.name + ': ' + subst(webhookHeader(w, h)))); });
        var body = subst(webhookBody(w));
        try { body = JSON.stringify(JSON.parse(body)); } catch (e) { }
        lines.push('-d ' + sq(body));
        return lines.join(' \\\n  ');
    }

    function webhookHtml(w) {
        var open = !!state.whOpen[w.name], m = w.method.toLowerCase();
        var html = '<article id="' + esc(w.id) + '" class="g4t-op g4t-webhook' + (open ? ' is-open' : '') + '">' +
            '<button type="button" class="g4t-op-head" data-action="toggle-webhook" data-value="' + esc(w.name) + '" aria-expanded="' + open + '">' +
            '<span class="g4t-method m-' + esc(m) + '">' + esc(w.method) + '</span><span class="g4t-path">' + esc(w.name) + '</span><span class="g4t-op-summary" dir="auto">' + esc(w.summary) + '</span>' +
            '<span class="g4t-op-tail">' + ICON.chev + '</span></button>';
        if (!open) { return html + '</article>'; }

        html += '<div class="g4t-op-body"><div class="g4t-op-intro"><p class="g4t-op-desc" dir="auto">' + esc(w.desc || w.summary || 'Sent by the API to the URL you registered for it.') + '</p></div>';
        if (w.headers.length) {
            html += '<div class="g4t-block"><h3>Headers</h3>' + rowsHtml(w.headers.map(function (h) {
                return { name: h.name, type: typeLabel(h.schema || {}), req: !!h.required, note: [h.description, h.example ? 'e.g. ' + h.example : ''].filter(Boolean).join(' · ') || '—', level: 0 };
            })) + '</div>';
        }
        var view = w.schema ? (state.whView[w.name] || 'example') : 'example';
        html += '<div class="g4t-block"><div class="g4t-block-head"><h3>Payload</h3><span class="g4t-mono g4t-muted" style="font-size:12px">application/json</span>' +
            (w.schema ? '<div class="g4t-seg g4t-view-toggle" role="tablist" aria-label="Payload view" style="grid-template-columns:repeat(2,auto)"><button type="button" role="tab" aria-selected="' + (view === 'example') + '" data-action="webhook-view" data-value="' + esc(w.name) + '" data-view="example">Example</button><button type="button" role="tab" aria-selected="' + (view === 'schema') + '" data-action="webhook-view" data-value="' + esc(w.name) + '" data-view="schema">Schema</button></div>' : '') + '</div>';
        if (view === 'schema') {
            var r = resolve(w.schema), rows = fields(w.schema);
            if (!rows.length && schemaType(r.schema) === 'array' && r.schema.items) { rows = fields(r.schema.items); }
            html += '<div class="g4t-type" style="font-size:12.5px">' + esc(typeLabel(w.schema)) + '</div>' + (rows.length ? rowsHtml(rows) : '<pre class="g4t-code is-muted">' + esc(pretty(r.schema)) + '</pre>');
        } else {
            html += '<pre class="g4t-code">' + (w.example !== null ? highlight(pretty(w.example)) : esc('No payload documented')) + '</pre>';
        }
        html += '</div>' + webhookTryHtml(w);
        return html + '</div></article>';
    }

    function webhookTryHtml(w) {
        var busy = !!state.whBusy[w.name], res = state.whResults[w.name], text = webhookBody(w);
        var row = function (label, control, hint) {
            return '<div class="g4t-form-row"><label class="g4t-form-label"><span class="g4t-field"><span class="g4t-field-name">' + esc(label) + '</span></span></label><div class="g4t-form-control">' + control + (hint ? '<div class="g4t-form-hint">' + esc(hint) + '</div>' : '') + '</div></div>';
        };
        var html = '<div class="g4t-try"><div class="g4t-try-title"><h3 style="margin:0;font-size:13px;font-weight:600">Send test webhook</h3><span>from this browser, never from the API server</span></div>' +
            '<div class="g4t-form">' + row('Your URL', '<input type="url" data-bind="webhook-url" value="' + esc(state.whUrl) + '" placeholder="https://your-app.example/webhooks" autocomplete="off" spellcheck="false">', 'The endpoint of your app that receives this webhook; {{variables}} work here.') +
            w.headers.map(function (h) {
                return row(h.name, '<input type="text" data-bind="webhook-header" data-value="' + esc(w.name) + '" data-name="' + esc(h.name) + '" value="' + esc(webhookHeader(w, h)) + '" autocomplete="off" spellcheck="false">', h.description || '');
            }).join('') + '</div>' +
            '<div class="g4t-try-section"><div class="g4t-try-label">Body <span class="g4t-mono g4t-muted">application/json</span></div><textarea class="g4t-textarea" data-bind="webhook-body" data-value="' + esc(w.name) + '" rows="' + Math.min(14, text.split('\n').length + 1) + '" spellcheck="false" aria-label="Webhook body">' + esc(text) + '</textarea></div>' +
            '<div class="g4t-actions"><button type="button" class="g4t-btn g4t-btn-primary" data-action="webhook-send" data-value="' + esc(w.name) + '"' + (busy ? ' disabled' : '') + '>' + (busy ? spinner() + 'Sending…' : 'Send test webhook') + '</button>' +
            '<button type="button" class="g4t-btn" data-action="webhook-curl" data-value="' + esc(w.name) + '">' + ICON.term + 'Copy as cURL</button>' +
            (res ? '<button type="button" class="g4t-btn g4t-btn-ghost" data-action="webhook-clear" data-value="' + esc(w.name) + '">Clear</button>' : '') + '</div>';
        if (res && res.opaque) {
            html += '<div class="g4t-result" aria-live="polite"><div class="g4t-actions"><span style="font-size:13px;font-weight:600">Sent</span><span class="g4t-status g4t-neu">no response</span><span class="g4t-muted" style="font-size:12px">' + res.ms + ' ms</span></div>' +
                '<div class="g4t-notice is-note" role="note">' + ICON.info + '<span>Your server does not allow this page to read its answers (CORS), so the webhook went as plain text instead' + (w.headers.length ? ', without the headers above' : '') + ', and its response could not be read. Check your server’s logs, or use Copy as cURL.</span></div></div>';
        } else if (res) {
            var hasJson = res.json !== undefined && res.json !== null;
            html += '<div class="g4t-result" aria-live="polite"><div class="g4t-actions"><span style="font-size:13px;font-weight:600">Your server answered</span><span class="g4t-status ' + statusClass(res.code) + '">' + (res.code || '—') + ' ' + esc(res.text) + '</span><span class="g4t-muted" style="font-size:12px">' + res.ms + ' ms</span></div>' +
                '<pre class="g4t-code">' + (res.code === 0 ? esc(res.raw) : res.raw === '' ? 'No content' : hasJson ? highlight(pretty(res.json)) : esc(res.raw.slice(0, 20000))) + '</pre></div>';
        }
        return html + '</div>';
    }
    function renderWebhook(name) {
        var w = webhookByName(name), el = w && document.getElementById(w.id);
        if (!el) { return; }
        var holder = document.createElement('div');
        holder.innerHTML = webhookHtml(w);
        el.replaceWith(holder.firstChild);
    }

    function sendWebhook(w) {
        var url = webhookTarget(), body = subst(webhookBody(w)), headers = { 'Content-Type': 'application/json' };
        if (!/^https?:\/\/\S+$/i.test(url)) { toast('Type the URL of your app that receives this webhook (http:// or https://)'); return; }
        try { JSON.parse(body); } catch (e) { toast('The body isn’t valid JSON'); return; }
        w.headers.forEach(function (h) { var value = subst(webhookHeader(w, h)); if (value !== '') { headers[h.name] = value; } });
        state.whBusy[w.name] = true; renderWebhook(w.name);
        var started = performance.now(), ms = function () { return Math.round(performance.now() - started); };
        fetch(url, { method: w.method, headers: headers, body: body })
            .then(function (response) {
                return response.text().then(function (text) {
                    var json; try { json = text ? JSON.parse(text) : null; } catch (e) { json = undefined; }
                    return { code: response.status, text: statusText(response.status, response.statusText), ms: ms(), json: json, raw: text };
                });
            })
            .catch(function (error) {
                // Usually CORS: a plain-text POST needs no permission, but its answer cannot be read.
                if (w.method !== 'POST') { throw error; }
                return fetch(url, { method: 'POST', mode: 'no-cors', headers: { 'Content-Type': 'text/plain' }, body: body }).then(function () { return { opaque: true, ms: ms() }; });
            })
            .catch(function (error) {
                return { code: 0, text: 'Network error', ms: ms(), raw: error.message + '\n\nThe webhook did not reach ' + url + '. Check the URL and that your server is running.' };
            })
            .then(function (res) { state.whResults[w.name] = res; state.whBusy[w.name] = false; renderWebhook(w.name); });
    }

    function openWebhook(name) {
        var w = webhookByName(name);
        if (!w) { return false; }
        state.whOpen[name] = true;
        if (state.filter && !webhookMatches(w, state.filter.trim().toLowerCase())) { state.filter = ''; els.filter.value = ''; }
        setDrawer(false); renderNav(); renderOps();
        try { history.replaceState(null, '', '#' + w.id); } catch (e) { }
        scrollToId(w.id);
        return true;
    }

    GROUPS.push({
        nav: function (q) {
            var list = webhookList().filter(function (w) { return webhookMatches(w, q); });
            if (!list.length) { return ''; }
            return '<div class="g4t-nav-group"><button type="button" class="g4t-nav-tag" data-action="goto-tag" data-anchor="g4t-webhooks"><span>Webhooks</span><span>' + list.length + '</span></button>' + list.map(function (w) {
                return '<button type="button" class="g4t-nav-op" data-action="goto-webhook" data-value="' + esc(w.name) + '"><span class="g4t-nav-method m-' + esc(w.method.toLowerCase()) + '">' + esc(w.method) + '</span><span class="g4t-nav-summary g4t-mono">' + esc(w.name) + '</span></button>';
            }).join('') + '</div>';
        },
        body: function (q) {
            var list = webhookList().filter(function (w) { return webhookMatches(w, q); });
            if (!list.length) { return ''; }
            return '<section id="g4t-webhooks" class="g4t-section"><div class="g4t-section-head"><h2>Webhooks</h2><span>Requests this API sends to your app when something happens. Answer with any 2xx status.</span></div><div class="g4t-op-list">' + list.map(webhookHtml).join('') + '</div></section>';
        },
        open: function (hash) {
            var w = webhookList().filter(function (x) { return x.id === hash; })[0];
            return w ? openWebhook(w.name) : false;
        }
    });

    ACTIONS['toggle-webhook'] = function (el, e, op, value) {
        state.whOpen[value] = !state.whOpen[value]; renderWebhook(value);
        var w = webhookByName(value);
        if (w && state.whOpen[value]) { try { history.replaceState(null, '', '#' + w.id); } catch (err) { } }
    };
    ACTIONS['goto-webhook'] = function (el, e, op, value) { openWebhook(value); };
    ACTIONS['webhook-view'] = function (el, e, op, value) { state.whView[value] = el.getAttribute('data-view'); renderWebhook(value); };
    ACTIONS['webhook-send'] = function (el, e, op, value) { var w = webhookByName(value); if (w) { sendWebhook(w); } };
    ACTIONS['webhook-curl'] = function (el, e, op, value) { var w = webhookByName(value); if (w) { copy(webhookCurl(w), 'cURL copied'); } };
    ACTIONS['webhook-clear'] = function (el, e, op, value) { delete state.whResults[value]; renderWebhook(value); };
    BINDS['webhook-url'] = function (el) { state.whUrl = el.value; store.set('webhookUrl', el.value); };
    BINDS['webhook-body'] = function (el) { state.whBodies[el.getAttribute('data-value')] = el.value; };
    BINDS['webhook-header'] = function (el) { var name = el.getAttribute('data-value'); (state.whHeaders[name] = state.whHeaders[name] || {})[el.getAttribute('data-name')] = el.value; };
@endverbatim
