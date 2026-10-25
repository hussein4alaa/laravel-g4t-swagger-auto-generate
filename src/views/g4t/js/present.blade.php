@verbatim
    /* ---------- present mode: one endpoint, full screen, large type ---------- */

    function presentList() { return filteredTags().reduce(function (all, t) { return all.concat(t.ops); }, []); }
    OVERLAYS.present = function (o) {
        var list = presentList(), index = list.map(function (x) { return x.id; }).indexOf(o.id), op = opById(o.id);
        if (!op) { return ''; }
        // Rendered like an open endpoint, without the Try it out form.
        var expanded = state.expanded[op.id], trying = state.trying[op.id];
        state.expanded[op.id] = true; state.trying[op.id] = false;
        var html = opHtml(op);
        state.expanded[op.id] = expanded; state.trying[op.id] = trying;
        return '<div class="g4t-present" role="dialog" aria-modal="true" aria-label="Present ' + esc(op.method + ' ' + op.path) + '">' +
            '<div class="g4t-present-bar"><span class="g4t-muted">' + esc(op.tag) + '</span><span class="g4t-present-count">' + (index + 1) + ' / ' + list.length + '</span>' +
            '<span class="g4t-present-tools"><button type="button" class="g4t-btn" data-action="present-step" data-value="-1"' + (index <= 0 ? ' disabled' : '') + ' aria-label="Previous endpoint">←</button>' +
            '<button type="button" class="g4t-btn" data-action="present-step" data-value="1"' + (index >= list.length - 1 ? ' disabled' : '') + ' aria-label="Next endpoint">→</button>' +
            '<button type="button" class="g4t-btn" data-action="close-overlay">Exit <kbd>esc</kbd></button></span></div>' +
            '<div class="g4t-present-stage">' + html.replace('id="op-', 'data-present-op="') + '</div></div>';
    };
    function openPresent(id) {
        openOverlay({ kind: 'present', id: id, full: true });
        var root = document.documentElement;
        if (root.requestFullscreen && !document.fullscreenElement) { root.requestFullscreen().catch(function () { }); }
    }
    function presentStep(delta) {
        var list = presentList(), ids = list.map(function (x) { return x.id; }), index = ids.indexOf(state.overlay.id) + delta;
        if (index >= 0 && index < ids.length) { state.overlay.id = ids[index]; state.active = ids[index]; renderOverlay(); }
    }
    ACTIONS.present = function (el, e, op) { if (op) { openPresent(op.id); } };
    ACTIONS['present-step'] = function (el, e, op, value) { presentStep(Number(value)); };
    KEYS.push(function (e) {
        if (!state.overlay || state.overlay.kind !== 'present') { return false; }
        if (e.target && /^(input|textarea|select)$/i.test(e.target.tagName)) { return false; }
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === ' ') { e.preventDefault(); presentStep(1); return true; }
        if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') { e.preventDefault(); presentStep(-1); return true; }
        return false;
    });
    document.addEventListener('fullscreenchange', function () {
        if (!document.fullscreenElement && state.overlay && state.overlay.kind === 'present') { closeOverlay(); }
    });
@endverbatim
