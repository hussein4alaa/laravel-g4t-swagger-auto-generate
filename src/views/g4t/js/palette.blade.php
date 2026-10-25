@verbatim
    /* ---------- command palette (Ctrl / ⌘ + K) ---------- */

    var paletteItems = [];
    function paletteSource() {
        var items = [], model = state.model;
        if (model) {
            model.ops.forEach(function (op) {
                items.push({ group: 'Endpoints', label: op.summary || op.path, detail: op.method + ' ' + op.path, method: op.method, keywords: op.tag + ' ' + op.status, run: function () { gotoOp(op.id); } });
            });
            model.tags.forEach(function (t) { items.push({ group: 'Sections', label: t.name, detail: t.ops.length + ' endpoints', run: function () { scrollToId(t.anchor); } }); });
        }
        var actions = [];
        if (LOGINS.length) { LOGINS.forEach(function (l, i) { actions.push({ label: LOGINS.length > 1 ? 'Log in as ' + l.name : 'Log in', run: function () { state.loginIndex = i; store.set('loginIndex', String(i)); openLogin('credentials'); } }); }); }
        actions.push({ label: hasAnyCred() ? 'Change credentials' : 'Authorize', detail: 'tokens and keys', run: function () { openLogin('token'); } });
        if (hasAnyCred()) { actions.push({ label: 'Log out', run: function () { state.creds = {}; saveJson('creds', state.creds); setToken(null, 'Logged out · credentials cleared'); } }); }
        state.envs.forEach(function (env) { if (env.id !== state.envId) { actions.push({ label: 'Switch to ' + env.name, detail: 'environment', keywords: 'env', run: function () { switchEnv(env.id); } }); } });
        actions.push({ label: 'Manage environments', run: function () { state.envsOpen = true; state.envEdit = state.envId; renderEnvs(); } });
        [['json', 'Download JSON'], ['yaml', 'Download YAML']].forEach(function (x) { actions.push({ label: x[1], detail: 'OpenAPI document', run: function () { downloadSpec(x[0]); } }); });
        [['postman', 'Export Postman collection'], ['postman-env', 'Export Postman environment'], ['insomnia', 'Export to Insomnia']].forEach(function (x) { actions.push({ label: x[1], run: function () { exportClient(x[0]); } }); });
        [['light', 'Light mode'], ['dark', 'Dark mode'], ['system', 'System mode']].forEach(function (x) { if (state.mode !== x[0]) { actions.push({ label: x[1], detail: 'appearance', run: function () { state.mode = x[0]; store.set('mode', x[0]); applyRoot(); renderAppear(); } }); } });
        if (hasTranslations(state.spec)) { actions.push({ label: state.lang === 'ar' ? 'English' : 'العربية', detail: 'language', run: function () { setLang(state.lang === 'ar' ? 'en' : 'ar'); } }); }
        if (model && Object.keys((state.spec.components || {}).schemas || {}).length) { actions.push({ label: 'Models diagram', detail: 'how the schemas relate', run: function () { openDiagram(); } }); }
        if (state.active && opById(state.active)) { actions.push({ label: 'Present ' + (opById(state.active).summary || opById(state.active).path), detail: 'full screen', keywords: 'present', run: function () { openPresent(state.active); } }); }
        actions.forEach(function (a) { a.group = 'Actions'; });
        return items.concat(actions);
    }
    function paletteResults(q) {
        q = q.trim().toLowerCase();
        var words = q.split(/\s+/).filter(Boolean);
        var list = paletteSource().filter(function (item) {
            var hay = (item.label + ' ' + (item.detail || '') + ' ' + (item.keywords || '')).toLowerCase();
            return words.every(function (w) { return hay.indexOf(w) !== -1; });
        });
        if (q.length >= 2 && state.model) {
            // Fields: "phone" finds every endpoint that sends or returns it.
            var index = fieldIndex(), seen = {};
            state.model.ops.forEach(function (op) {
                ((index[op.id] || {}).fields || []).forEach(function (f) {
                    var key = op.id + ':' + f.name;
                    if (seen[key] || f.name.toLowerCase().indexOf(q) === -1) { return; }
                    seen[key] = true;
                    list.push({ group: 'Fields', label: f.name, detail: f.where + ' · ' + op.method + ' ' + op.path, method: op.method, run: function () { gotoOp(op.id); } });
                });
            });
        }
        var rank = function (item) { var l = item.label.toLowerCase(); return l === q ? 0 : l.indexOf(q) === 0 ? 1 : 2; };
        return list.map(function (item, i) { return { item: item, i: i }; }).sort(function (a, b) {
            var groups = ['Endpoints', 'Fields', 'Sections', 'Actions'];
            return q ? (rank(a.item) - rank(b.item)) || (groups.indexOf(a.item.group) - groups.indexOf(b.item.group)) || (a.i - b.i) : a.i - b.i;
        }).map(function (x) { return x.item; }).slice(0, 60);
    }
    function paletteListHtml() {
        var o = state.overlay, group = null;
        paletteItems = paletteResults(o.q || '');
        if (!paletteItems.length) { return '<div class="g4t-palette-empty">Nothing matches “' + esc(o.q) + '”.</div>'; }
        o.index = Math.min(o.index || 0, paletteItems.length - 1);
        return paletteItems.map(function (item, i) {
            var head = item.group !== group ? '<div class="g4t-palette-group">' + esc(item.group) + '</div>' : '';
            group = item.group;
            return head + '<button type="button" role="option" class="g4t-palette-item" aria-selected="' + (i === o.index) + '" data-action="palette-run" data-value="' + i + '">' +
                (item.method ? '<span class="g4t-method m-' + esc(item.method.toLowerCase()) + '">' + esc(item.method) + '</span>' : '<span class="g4t-palette-icon">›</span>') +
                '<span class="g4t-palette-label" dir="auto">' + esc(item.label) + '</span><span class="g4t-palette-detail g4t-mono">' + esc(item.detail || '') + '</span></button>';
        }).join('');
    }
    OVERLAYS.palette = function (o) {
        return '<div class="g4t-login-dialog g4t-palette" role="dialog" aria-modal="true" aria-label="Command palette"><div class="g4t-palette-search">' + ICON.search +
            '<input id="g4t-palette-input" type="search" placeholder="Search endpoints, fields, actions…" autocomplete="off" spellcheck="false" value="' + esc(o.q || '') + '" data-autofocus aria-controls="g4t-palette-list"><kbd>esc</kbd></div>' +
            '<div class="g4t-palette-list" id="g4t-palette-list" role="listbox">' + paletteListHtml() + '</div></div>';
    };
    function renderPaletteList() {
        var list = document.getElementById('g4t-palette-list');
        if (list) { list.innerHTML = paletteListHtml(); var on = list.querySelector('[aria-selected="true"]'); if (on) { on.scrollIntoView({ block: 'nearest' }); } }
    }
    function openPalette() { openOverlay({ kind: 'palette', q: '', index: 0 }); }
    function runPaletteItem(i) {
        var item = paletteItems[i];
        if (!item) { return; }
        closeOverlay();
        item.run();
    }
    ACTIONS['open-palette'] = function () { openPalette(); };
    ACTIONS['palette-run'] = function (el, e, op, value) { runPaletteItem(Number(value)); };
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'g4t-palette-input' && state.overlay && state.overlay.kind === 'palette') {
            state.overlay.q = e.target.value; state.overlay.index = 0; renderPaletteList();
        }
    });
    KEYS.push(function (e) {
        if ((e.metaKey || e.ctrlKey) && !e.altKey && String(e.key).toLowerCase() === 'k') {
            e.preventDefault();
            if (state.overlay && state.overlay.kind === 'palette') { closeOverlay(); } else { openPalette(); }
            return true;
        }
        if (!state.overlay || state.overlay.kind !== 'palette') { return false; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var n = paletteItems.length;
            if (n) { state.overlay.index = (state.overlay.index + (e.key === 'ArrowDown' ? 1 : -1) + n) % n; renderPaletteList(); }
            return true;
        }
        if (e.key === 'Enter') { e.preventDefault(); runPaletteItem(state.overlay.index); return true; }
        return false;
    });
@endverbatim
