@verbatim
    /* ---------- field index: smart search and "where is this field used" ---------- */

    var fieldIndexCache = null;
    /** "tags[]" → "tags", "dimensions[width]" → "width": the name a reader thinks of. */
    function leafName(name) { return String(name).replace(/\[\]$/, '').replace(/^.*\[([^\[\]]+)\]$/, '$1'); }
    function walkFields(raw, prefix, where, out, depth, seen) {
        var r = resolve(raw), s = r.schema;
        if (r.name) { if (seen.indexOf(r.name) !== -1) { return; } seen = seen.concat([r.name]); }
        if (schemaType(s) === 'array' && s.items) { walkFields(s.items, prefix ? prefix + '[]' : '', where, out, depth, seen); return; }
        Object.keys(s.properties || {}).forEach(function (key) {
            var path = prefix ? prefix + '.' + key : key;
            out.push({ name: leafName(key), path: path, where: where });
            if (depth < 5) { walkFields(s.properties[key], path, where, out, depth + 1, seen); }
        });
    }
    /** Every field of every operation: parameters, request body and success responses ($ref resolved, nested). */
    function fieldIndex() {
        if (fieldIndexCache && fieldIndexCache.model === state.model) { return fieldIndexCache.byOp; }
        var byOp = {};
        (state.model ? state.model.ops : []).forEach(function (op) {
            var out = [];
            op.params.forEach(function (p) { out.push({ name: leafName(p.name), path: p.name, where: p.in }); });
            if (op.body) {
                var ctype = op.ctypes.indexOf('application/json') !== -1 ? 'application/json' : op.ctypes[0];
                walkFields(op.body.content[ctype].schema || {}, '', 'request', out, 0, []);
            }
            op.responses.forEach(function (r) { if (r.schema && typeof r.code === 'number' && r.code < 300) { walkFields(r.schema, '', 'response', out, 0, []); } });
            var text = [op.desc].concat(op.params.map(function (p) { return p.description || ''; }));
            op.responses.forEach(function (r) { r.examples.forEach(function (ex) { if (ex.has && !ex.generated && ex.value != null) { text.push(JSON.stringify(ex.value).slice(0, 4000)); } }); });
            byOp[op.id] = { fields: out, text: text.join(' ').toLowerCase() };
        });
        fieldIndexCache = { model: state.model, byOp: byOp };
        return byOp;
    }
    /** Why an operation matches the filter: fields first, then descriptions / examples. */
    function searchMatch(op, tagName, q) {
        if (!q) { return { hit: true }; }
        if ((op.method + ' ' + op.path + ' ' + op.summary + ' ' + tagName + ' ' + op.status).toLowerCase().indexOf(q) !== -1) { return { hit: true }; }
        var entry = fieldIndex()[op.id] || { fields: [], text: '' }, seen = {}, fields = [];
        entry.fields.forEach(function (f) {
            if (f.name.toLowerCase().indexOf(q) === -1) { return; }
            var key = f.where + ':' + f.path;
            if (!seen[key]) { seen[key] = true; fields.push(f); }
        });
        if (fields.length) { return { hit: true, fields: fields }; }
        return entry.text.indexOf(q) !== -1 ? { hit: true, text: true } : { hit: false };
    }
    function matchHtml(op) {
        var m = state.matches[op.id];
        if (!m || (!m.fields && !m.text)) { return ''; }
        if (m.text) { return '<span class="g4t-op-match">in description or example</span>'; }
        var byWhere = {};
        m.fields.forEach(function (f) { (byWhere[f.where] = byWhere[f.where] || []).push(f.path); });
        return '<span class="g4t-op-match">' + Object.keys(byWhere).map(function (w) { return esc(w) + ' <code class="g4t-mono">' + byWhere[w].slice(0, 3).map(esc).join(', ') + (byWhere[w].length > 3 ? '…' : '') + '</code>'; }).join(' · ') + '</span>';
    }

    /** Operations that use a field with this name, and where. */
    function fieldUsage(name) {
        var target = leafName(name).toLowerCase(), index = fieldIndex(), out = [];
        (state.model ? state.model.ops : []).forEach(function (op) {
            var hits = ((index[op.id] || {}).fields || []).filter(function (f) { return f.name.toLowerCase() === target; });
            if (hits.length) { out.push({ op: op, hits: hits }); }
        });
        return out;
    }
    function usageHtml(name) {
        var rows = fieldUsage(name), where = {};
        rows.forEach(function (r) { r.hits.forEach(function (h) { where[h.where] = (where[h.where] || 0) + 1; }); });
        return '<div class="g4t-login-dialog g4t-overlay-dialog" role="dialog" aria-modal="true" aria-labelledby="g4t-usage-title"><div class="g4t-overlay-body">' +
            '<div class="g4t-env-head"><h2 id="g4t-usage-title">Where <code class="g4t-mono">' + esc(leafName(name)) + '</code> is used</h2><button type="button" class="g4t-file-clear" data-action="close-overlay" aria-label="Close">×</button></div>' +
            '<p class="g4t-muted" style="margin:0;font-size:13px">' + rows.length + ' endpoint' + (rows.length === 1 ? '' : 's') + (Object.keys(where).length ? ' · ' + Object.keys(where).map(function (w) { return where[w] + ' in ' + esc(w); }).join(', ') : '') + '</p>' +
            '<div class="g4t-usage">' + rows.map(function (r) {
                return '<button type="button" class="g4t-usage-row" data-action="goto" data-op="' + esc(r.op.id) + '"><span class="g4t-method m-' + esc(r.op.method.toLowerCase()) + '">' + esc(r.op.method) + '</span><span class="g4t-mono g4t-usage-path">' + esc(r.op.path) + '</span>' +
                    '<span class="g4t-usage-where">' + r.hits.map(function (h) { return '<span>' + esc(h.where) + ' <code class="g4t-mono">' + esc(h.path) + '</code></span>'; }).join('') + '</span></button>';
            }).join('') + '</div></div></div>';
    }
@endverbatim
