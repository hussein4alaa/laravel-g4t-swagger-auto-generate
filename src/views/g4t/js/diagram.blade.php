@verbatim
    /* ---------- models diagram: components.schemas and the $ref links between them ---------- */

    var DIAGRAM = { width: 230, head: 30, row: 20, maxRows: 12, gapX: 110, gapY: 28, pad: 24 };
    function refTarget(raw) {
        if (!raw) { return null; }
        if (raw.$ref) { return { name: raw.$ref.split('/').pop(), many: false }; }
        var s = raw;
        if (schemaType(s) === 'array' && s.items && s.items.$ref) { return { name: s.items.$ref.split('/').pop(), many: true }; }
        return null;
    }
    function diagramModel() {
        var schemas = ((state.spec || {}).components || {}).schemas || {}, names = Object.keys(schemas), nodes = {}, edges = [], referenced = {};
        names.forEach(function (name) {
            var s = schemas[name] || {}, required = s.required || [], fieldsList = [];
            Object.keys(s.properties || {}).forEach(function (key) {
                var prop = s.properties[key], target = refTarget(prop);
                fieldsList.push({ key: key, type: target ? (target.many ? target.name + '[]' : target.name) : typeLabel(prop), req: required.indexOf(key) !== -1 });
                if (target && schemas[target.name]) { edges.push({ from: name, field: key, to: target.name, many: target.many }); if (target.name !== name) { referenced[target.name] = true; } }
            });
            nodes[name] = { name: name, fields: fieldsList };
        });
        // Columns: roots (not referenced by another model) first, then what they point to.
        var level = {}, queue = names.filter(function (n) { return !referenced[n]; });
        if (!queue.length) { queue = names.slice(0, 1); }
        queue.forEach(function (n) { level[n] = 0; });
        while (queue.length) {
            var current = queue.shift();
            edges.filter(function (e) { return e.from === current && level[e.to] === undefined; }).forEach(function (e) { level[e.to] = level[current] + 1; queue.push(e.to); });
        }
        names.forEach(function (n) { if (level[n] === undefined) { level[n] = 0; } });
        var columns = {}, heights = {};
        // Linked models first, so the relations are what you see without scrolling.
        var linked = {};
        edges.forEach(function (e) { linked[e.from] = true; linked[e.to] = true; });
        names.slice().sort(function (a, b) { return (linked[b] ? 1 : 0) - (linked[a] ? 1 : 0); }).forEach(function (n) { (columns[level[n]] = columns[level[n]] || []).push(n); });
        Object.keys(columns).forEach(function (col) {
            var y = DIAGRAM.pad;
            columns[col].forEach(function (n) {
                var shown = Math.min(nodes[n].fields.length, DIAGRAM.maxRows) + (nodes[n].fields.length > DIAGRAM.maxRows ? 1 : 0);
                nodes[n].x = DIAGRAM.pad + Number(col) * (DIAGRAM.width + DIAGRAM.gapX);
                nodes[n].y = y;
                nodes[n].h = DIAGRAM.head + Math.max(shown, 1) * DIAGRAM.row + 8;
                y += nodes[n].h + DIAGRAM.gapY;
            });
            heights[col] = y;
        });
        var cols = Object.keys(columns).length;
        return { nodes: nodes, edges: edges, width: DIAGRAM.pad * 2 + cols * DIAGRAM.width + (cols - 1) * DIAGRAM.gapX, height: Math.max.apply(null, Object.keys(heights).map(function (k) { return heights[k]; }).concat([120])) };
    }
    /** Endpoints whose request or success response reaches this model (directly or nested). */
    function endpointsUsing(name) {
        var seenOps = [];
        (state.model ? state.model.ops : []).forEach(function (op) {
            var found = false, visit = function (raw, depth, seen) {
                if (found || !raw || depth > 6) { return; }
                if (raw.$ref) { var n = raw.$ref.split('/').pop(); if (n === name) { found = true; return; } if (seen.indexOf(n) !== -1) { return; } seen = seen.concat([n]); raw = resolveRef(raw.$ref) || {}; }
                if (raw.items) { visit(raw.items, depth + 1, seen); }
                Object.keys(raw.properties || {}).forEach(function (k) { visit(raw.properties[k], depth + 1, seen); });
            };
            op.responses.forEach(function (r) { if (r.schema && typeof r.code === 'number' && r.code < 300) { visit(r.schema, 0, []); } });
            if (op.body) { Object.keys(op.body.content).forEach(function (c) { visit(op.body.content[c].schema, 0, []); }); }
            if (found) { seenOps.push(op); }
        });
        return seenOps;
    }
    OVERLAYS.diagram = function (o) {
        var d = diagramModel(), selected = o.selected, zoom = o.zoom || 1, D = DIAGRAM;
        var related = {};
        if (selected) { related[selected] = true; d.edges.forEach(function (e) { if (e.from === selected) { related[e.to] = true; } if (e.to === selected) { related[e.from] = true; } }); }
        var edgeSvg = d.edges.map(function (e) {
            var a = d.nodes[e.from], b = d.nodes[e.to], index = a.fields.map(function (f) { return f.key; }).indexOf(e.field);
            var sy = a.y + D.head + Math.min(index, D.maxRows) * D.row + D.row / 2, sx = a.x + D.width, tx = b.x, ty = b.y + D.head / 2;
            var back = b.x <= a.x, c1 = back ? sx + 60 : sx + D.gapX / 2, c2 = back ? tx - 60 : tx - D.gapX / 2;
            var on = selected && (e.from === selected || e.to === selected);
            return '<path class="g4t-dg-edge' + (on ? ' is-on' : selected ? ' is-dim' : '') + '" d="M' + sx + ' ' + sy + ' C' + c1 + ' ' + sy + ', ' + c2 + ' ' + ty + ', ' + tx + ' ' + ty + '"/>' +
                '<text class="g4t-dg-card' + (on ? ' is-on' : '') + '" x="' + (tx - 10) + '" y="' + (ty - 6) + '" text-anchor="end">' + (e.many ? '*' : '1') + '</text>';
        }).join('');
        var nodeSvg = Object.keys(d.nodes).map(function (name) {
            var n = d.nodes[name], dim = selected && !related[name];
            var rows = n.fields.slice(0, D.maxRows).map(function (f, i) {
                var y = n.y + D.head + i * D.row + 14, isRef = /^[A-Z]/.test(f.type) && d.nodes[f.type.replace(/\[\]$/, '')];
                return '<text class="g4t-dg-field" x="' + (n.x + 12) + '" y="' + y + '">' + esc(f.key) + (f.req ? ' *' : '') + '</text><text class="g4t-dg-type' + (isRef ? ' is-ref' : '') + '" x="' + (n.x + D.width - 12) + '" y="' + y + '" text-anchor="end">' + esc(f.type.length > 22 ? f.type.slice(0, 21) + '…' : f.type) + '</text>';
            }).join('') + (n.fields.length > D.maxRows ? '<text class="g4t-dg-type" x="' + (n.x + 12) + '" y="' + (n.y + D.head + D.maxRows * D.row + 14) + '">+' + (n.fields.length - D.maxRows) + ' more</text>' : '');
            return '<g class="g4t-dg-node' + (name === selected ? ' is-selected' : '') + (dim ? ' is-dim' : '') + '" data-action="diagram-select" data-value="' + esc(name) + '" tabindex="0" role="button" aria-label="' + esc(name) + '">' +
                '<rect x="' + n.x + '" y="' + n.y + '" width="' + D.width + '" height="' + n.h + '" rx="10"/><rect class="g4t-dg-head" x="' + n.x + '" y="' + n.y + '" width="' + D.width + '" height="' + D.head + '" rx="10"/><rect class="g4t-dg-head" x="' + n.x + '" y="' + (n.y + D.head - 10) + '" width="' + D.width + '" height="10"/>' +
                '<text class="g4t-dg-name" x="' + (n.x + 12) + '" y="' + (n.y + 20) + '">' + esc(name) + '</text>' + rows + '</g>';
        }).join('');
        var side = '';
        if (selected && d.nodes[selected]) {
            var users = endpointsUsing(selected);
            side = '<aside class="g4t-dg-side"><h3>' + esc(selected) + '</h3><p class="g4t-muted">' + d.nodes[selected].fields.length + ' fields · used by ' + users.length + ' endpoint' + (users.length === 1 ? '' : 's') + '</p><div class="g4t-usage">' +
                users.map(function (op) { return '<button type="button" class="g4t-usage-row" data-action="goto" data-op="' + esc(op.id) + '"><span class="g4t-method m-' + esc(op.method.toLowerCase()) + '">' + esc(op.method) + '</span><span class="g4t-mono g4t-usage-path">' + esc(op.path) + '</span></button>'; }).join('') + '</div></aside>';
        }
        return '<div class="g4t-diagram" role="dialog" aria-modal="true" aria-label="Models diagram"><div class="g4t-present-bar"><b>Models</b><span class="g4t-muted">' + Object.keys(d.nodes).length + ' schemas · ' + d.edges.length + ' links · click a model to see its links and endpoints</span>' +
            '<span class="g4t-present-tools"><button type="button" class="g4t-btn" data-action="diagram-zoom" data-value="-1" aria-label="Zoom out">−</button><button type="button" class="g4t-btn" data-action="diagram-zoom" data-value="1" aria-label="Zoom in">+</button><button type="button" class="g4t-btn" data-action="close-overlay">Close <kbd>esc</kbd></button></span></div>' +
            '<div class="g4t-dg-body"><div class="g4t-dg-canvas"><svg width="' + Math.round(d.width * zoom) + '" height="' + Math.round(d.height * zoom) + '" viewBox="0 0 ' + d.width + ' ' + d.height + '" role="img" aria-label="Schemas and their relations">' + edgeSvg + nodeSvg + '</svg></div>' + side + '</div></div>';
    };
    function openDiagram() { openOverlay({ kind: 'diagram', full: true, zoom: 1, selected: null }); }
    ACTIONS['open-diagram'] = function () { openDiagram(); };
    ACTIONS['diagram-select'] = function (el, e, op, value) { state.overlay.selected = state.overlay.selected === value ? null : value; renderOverlay(); };
    ACTIONS['diagram-zoom'] = function (el, e, op, value) { state.overlay.zoom = Math.max(.4, Math.min(2, (state.overlay.zoom || 1) + Number(value) * .2)); renderOverlay(); };
@endverbatim
