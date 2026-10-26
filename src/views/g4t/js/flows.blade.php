@verbatim
    /* ---------- flows: documented requests run one after another, values of a response feeding the next ---------- */

    var flowCache = { spec: null, list: [] };
    state.flowOpen = {}; state.flowRuns = {};

    function opByOperationId(operationId) { return state.model ? state.model.ops.filter(function (o) { return o.operationId === operationId; })[0] || null : null; }
    /** Flows of the loaded document: { id, domId, name, desc, descLang, steps }. */
    function flowList() {
        if (!state.spec) { return []; }
        if (flowCache.spec === state.spec && flowCache.lang === state.lang) { return flowCache.list; }
        var used = {};
        flowCache = { spec: state.spec, lang: state.lang, list: (Array.isArray(state.spec['x-flows']) ? state.spec['x-flows'] : []).filter(function (f) { return f && f.id && Array.isArray(f.steps) && f.steps.length; }).map(function (f) {
            var d = f.description && typeof f.description === 'object' ? f.description : {}, ar = state.lang === 'ar' && d.ar;
            var domId = 'flow-' + slug(f.id);
            while (used[domId]) { domId += '_'; }
            used[domId] = true;
            return { id: String(f.id), domId: domId, name: String(f.name || 'Flow'), desc: ar ? d.ar : (d.en || d.ar || ''), descLang: ar || (!d.en && d.ar) ? 'ar' : 'en', steps: f.steps };
        }) };
        return flowCache.list;
    }
    function flowById(id) { return flowList().filter(function (f) { return f.id === id; })[0] || null; }
    function flowMatches(f, q) {
        var hay = (f.name + ' ' + f.desc + ' ' + f.steps.map(function (st) { var op = opByOperationId(st.operation); return op ? op.path + ' ' + op.summary : ''; }).join(' ')).toLowerCase();
        return !q || q.split(/\s+/).every(function (word) { return hay.indexOf(word) !== -1; });
    }
    /** A value of a response: "body.data.token", "headers.x-request-id" or "status"; undefined when it is not there. */
    function valueAt(res, from) {
        var parts = String(from || '').split('.'), root = parts.shift();
        if (root === 'status') { return parts.length ? undefined : res.code; }
        if (root === 'headers') { return (res.headers || {})[parts.join('.').toLowerCase()]; }
        if (root !== 'body') { return undefined; }
        return parts.reduce(function (v, k) { return v !== null && typeof v === 'object' && Object.prototype.hasOwnProperty.call(v, k) ? v[k] : undefined; }, res.json);
    }
    function asVariable(value) { return value !== null && typeof value === 'object' ? JSON.stringify(value) : String(value); }

    function runFlow(flow) {
        var run = state.flowRuns[flow.id] = { busy: true, steps: [], vars: {}, failed: false, done: false };
        var index = 0;
        var stop = function (entry, message) {
            entry.busy = false; entry.error = message;
            run.busy = false; run.failed = true; renderFlow(flow.id);
            toast('Step ' + index + ' failed · the flow stopped');
        };
        var next = function () {
            if (index >= flow.steps.length) { run.busy = false; run.done = true; renderFlow(flow.id); toast('Flow finished · ' + flow.steps.length + ' step' + (flow.steps.length > 1 ? 's' : '') + ' passed'); return; }
            var step = flow.steps[index++], op = opByOperationId(step.operation), entry = run.steps[index - 1] = { busy: true, set: {} };
            renderFlow(flow.id);
            if (!op) { stop(entry, 'The endpoint ' + step.operation + ' is not in this API definition.'); return; }
            var req = buildRequest(op, { flow: { params: step.params || {}, headers: step.headers || {}, body: step.body || '' }, vars: run.vars });
            if (req.error) { stop(entry, req.error + '.'); return; }
            entry.req = { method: req.method, url: req.url, headers: req.headers, body: req.body };
            if (req.missingVars.length) { stop(entry, 'Unknown variable' + (req.missingVars.length > 1 ? 's' : '') + ' ' + req.missingVars.map(function (n) { return '{{' + n + '}}'; }).join(', ') + ': no earlier step sets it and the environment has no such value.'); return; }
            sendRequest(req).then(function (res) {
                entry.res = res;
                if (res.code === 0 || res.code >= 400) { stop(entry, res.code === 0 ? 'The request did not reach the server.' : 'The server answered ' + res.code + ' ' + res.text + '.'); return; }
                var missing = [];
                (step.extract || []).forEach(function (x) {
                    var value = valueAt(res, x.from);
                    if (value === undefined || value === null) { missing.push(x.from); return; }
                    run.vars[x['var']] = entry.set[x['var']] = asVariable(value);
                });
                if (missing.length) { stop(entry, 'Not in the response: ' + missing.join(', ') + '.'); return; }
                entry.busy = false;
                next();
            });
        };
        next();
    }

    function flowRequestText(req) {
        var lines = [req.method + ' ' + req.url];
        Object.keys(req.headers).filter(function (h) { return h !== 'X-Requested-With'; }).forEach(function (h) {
            lines.push(h + ': ' + (h === 'Authorization' ? req.headers[h].replace(/^(\S+\s+)?(.*)$/, function (m, kind, secret) { return (kind || '') + previewSecret(secret); }) : req.headers[h]));
        });
        if (typeof req.body === 'string') { lines.push('', (function () { try { return pretty(JSON.parse(req.body)); } catch (e) { return req.body; } })()); }
        return lines.join('\n');
    }
    function flowStepHtml(flow, step, i) {
        var op = opByOperationId(step.operation), run = state.flowRuns[flow.id], entry = run && run.steps[i];
        var cls = !entry ? (run && run.failed ? ' is-skipped' : '') : entry.busy ? ' is-busy' : entry.error ? ' is-fail' : ' is-ok';
        var status = !entry ? '' : entry.busy ? spinner() : entry.res ? '<span class="g4t-status ' + statusClass(entry.res.code) + '">' + (entry.res.code || '—') + ' ' + esc(entry.res.text) + '</span><span class="g4t-muted" style="font-size:12px">' + entry.res.ms + ' ms</span>' : '<span class="g4t-status g4t-err">not sent</span>';
        var html = '<li class="g4t-flow-step' + cls + '"><div class="g4t-flow-step-head"><span class="g4t-flow-num">' + (i + 1) + '</span>' +
            (op ? '<span class="g4t-method m-' + esc(op.method.toLowerCase()) + '">' + esc(op.method) + '</span><button type="button" class="g4t-link-btn g4t-mono g4t-flow-path" data-action="goto" data-op="' + esc(op.id) + '">' + esc(op.path) + '</button><span class="g4t-muted g4t-flow-summary" dir="auto">' + esc(op.summary) + '</span>'
                : '<span class="g4t-mono g4t-muted">' + esc(step.operation) + '</span>') +
            '<span class="g4t-flow-state">' + status + '</span></div>';
        var extract = step.extract || [];
        if (extract.length) {
            html += '<div class="g4t-flow-sets">' + extract.map(function (x) {
                var value = entry && entry.set[x['var']];
                return '<span><code class="g4t-mono">{{' + esc(x['var']) + '}}</code> ← <code class="g4t-mono">' + esc(x.from) + '</code>' + (value !== undefined ? ' = <code class="g4t-mono" title="' + esc(value) + '">' + esc(previewSecret(value)) + '</code>' : '') + '</span>';
            }).join('') + '</div>';
        }
        if (entry && entry.error) { html += '<div class="g4t-notice is-deprecated" role="alert">' + ICON.alert + '<span>' + esc(entry.error) + '</span></div>'; }
        if (entry && entry.req) {
            var res = entry.res, hasJson = res && res.json !== undefined && res.json !== null;
            html += '<details class="g4t-flow-detail"' + (entry.error ? ' open' : '') + '><summary>Request and response</summary><pre class="g4t-code is-muted">' + esc(flowRequestText(entry.req)) + '</pre>' +
                (res ? '<pre class="g4t-code">' + (res.code === 204 || res.raw === '' ? 'No content' : hasJson ? highlight(pretty(res.json)) : esc(res.raw.slice(0, 20000))) + '</pre>' : '') + '</details>';
        }
        return html + '</li>';
    }
    function flowHtml(flow) {
        var open = !!state.flowOpen[flow.id], run = state.flowRuns[flow.id], missing = flow.steps.filter(function (st) { return !opByOperationId(st.operation); }).length;
        var html = '<article id="' + esc(flow.domId) + '" class="g4t-op g4t-flow' + (open ? ' is-open' : '') + '">' +
            '<button type="button" class="g4t-op-head" data-action="toggle-flow" data-value="' + esc(flow.id) + '" aria-expanded="' + open + '">' +
            '<span class="g4t-method m-flow">FLOW</span><span class="g4t-path g4t-flow-name" dir="auto">' + esc(flow.name) + '</span><span class="g4t-op-summary">' + flow.steps.length + ' step' + (flow.steps.length > 1 ? 's' : '') + '</span>' +
            '<span class="g4t-op-tail">' + (run && !run.busy ? '<span class="g4t-status ' + (run.failed ? 'g4t-err' : 'g4t-ok') + '">' + (run.failed ? 'failed' : 'passed') + '</span>' : '') + ICON.chev + '</span></button>';
        if (!open) { return html + '</article>'; }
        html += '<div class="g4t-op-body">' + (flow.desc ? '<p class="g4t-op-desc g4t-flow-desc" dir="' + (flow.descLang === 'ar' ? 'rtl' : 'auto') + '" lang="' + flow.descLang + '">' + esc(flow.desc) + '</p>' : '') +
            '<ol class="g4t-flow-steps">' + flow.steps.map(function (st, i) { return flowStepHtml(flow, st, i); }).join('') + '</ol>' +
            '<div class="g4t-actions"><button type="button" class="g4t-btn g4t-btn-primary" data-action="run-flow" data-value="' + esc(flow.id) + '"' + (run && run.busy || missing ? ' disabled' : '') + '>' + (run && run.busy ? spinner() + 'Running…' : run ? 'Run again' : 'Run flow') + '</button>' +
            (run && !run.busy ? '<button type="button" class="g4t-btn g4t-btn-ghost" data-action="clear-flow" data-value="' + esc(flow.id) + '">Clear</button>' : '') +
            '<span class="g4t-muted" style="font-size:12px">→ ' + esc(serverUrl()) + ' · with your environment and credentials</span></div>';
        var vars = run ? Object.keys(run.vars) : [];
        if (vars.length) {
            html += '<div class="g4t-block"><h3>Variables set</h3><div class="g4t-table">' + vars.map(function (name) {
                return '<div class="g4t-row"><div class="g4t-row-name"><span class="g4t-field-name">{{' + esc(name) + '}}</span></div><div class="g4t-note g4t-mono" style="word-break:break-all">' + esc(run.vars[name].length > 300 ? run.vars[name].slice(0, 300) + '…' : run.vars[name]) + '</div></div>';
            }).join('') + '</div></div>';
        }
        return html + '</div></article>';
    }
    function renderFlow(id) {
        var flow = flowById(id), el = flow && document.getElementById(flow.domId);
        if (!el) { return; }
        var holder = document.createElement('div');
        holder.innerHTML = flowHtml(flow);
        el.replaceWith(holder.firstChild);
    }
    function openFlow(id) {
        var flow = flowById(id);
        if (!flow) { return false; }
        state.flowOpen[id] = true;
        if (state.filter && !flowMatches(flow, state.filter.trim().toLowerCase())) { state.filter = ''; els.filter.value = ''; }
        setDrawer(false); renderNav(); renderOps();
        try { history.replaceState(null, '', '#' + flow.domId); } catch (e) { }
        scrollToId(flow.domId);
        return true;
    }

    GROUPS.push({
        nav: function (q) {
            var list = flowList().filter(function (f) { return flowMatches(f, q); });
            if (!list.length) { return ''; }
            return '<div class="g4t-nav-group"><button type="button" class="g4t-nav-tag" data-action="goto-tag" data-anchor="g4t-flows"><span>Flows</span><span>' + list.length + '</span></button>' + list.map(function (f) {
                return '<button type="button" class="g4t-nav-op" data-action="goto-flow" data-value="' + esc(f.id) + '"><span class="g4t-nav-method m-flow">FLOW</span><span class="g4t-nav-summary" dir="auto">' + esc(f.name) + '</span></button>';
            }).join('') + '</div>';
        },
        body: function (q) {
            var list = flowList().filter(function (f) { return flowMatches(f, q); });
            if (!list.length) { return ''; }
            return '<section id="g4t-flows" class="g4t-section"><div class="g4t-section-head"><h2>Flows</h2><span>Requests run one after another in one click; a value a step keeps, like {{token}}, feeds the next steps.</span></div><div class="g4t-op-list">' + list.map(flowHtml).join('') + '</div></section>';
        },
        open: function (hash) {
            var flow = flowList().filter(function (f) { return f.domId === hash; })[0];
            return flow ? openFlow(flow.id) : false;
        }
    });

    ACTIONS['toggle-flow'] = function (el, e, op, value) {
        state.flowOpen[value] = !state.flowOpen[value]; renderFlow(value);
        var flow = flowById(value);
        if (flow && state.flowOpen[value]) { try { history.replaceState(null, '', '#' + flow.domId); } catch (err) { } }
    };
    ACTIONS['goto-flow'] = function (el, e, op, value) { openFlow(value); };
    ACTIONS['run-flow'] = function (el, e, op, value) { var flow = flowById(value); if (flow && !(state.flowRuns[value] || {}).busy) { runFlow(flow); } };
    ACTIONS['clear-flow'] = function (el, e, op, value) { delete state.flowRuns[value]; renderFlow(value); };
@endverbatim
