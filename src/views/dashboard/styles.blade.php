<style>
    /* Dashboard pieces on top of the g4t UI tokens and components (g4t/styles). */
    .d-login { min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); }
    .d-login-card { width: min(380px, 100%); display: grid; gap: 14px; padding: 26px; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow); }
    .d-login-brand { display: flex; align-items: center; gap: 12px; }
    .d-login-title { font-size: 18px; font-weight: 650; letter-spacing: -.01em; }
    .d-field { display: grid; gap: 5px; font-size: 13px; font-weight: 500; min-width: 0; }
    .d-field > span.d-hint, .d-hint { font-size: 12px; font-weight: 400; color: var(--text-3); }
    .d-field input, .d-field select, .d-field textarea, .d-input { height: 36px; padding-inline: 10px; border: 1px solid var(--border-strong); border-radius: 8px; background: var(--surface); color: var(--text); font: 13.5px/1.3 inherit; min-width: 0; width: 100%; }
    .d-field textarea { height: auto; padding-block: 8px; resize: vertical; }
    .d-field input:focus, .d-field select:focus, .d-field textarea:focus, .d-input:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in oklab, var(--accent) 18%, transparent); }
    .d-field.is-invalid input, .d-field.is-invalid select, .d-field.is-invalid textarea, .d-input.is-invalid { border-color: var(--err-fg); }
    .d-error { font-size: 12px; font-weight: 500; color: var(--err-fg); }

    .d-app { min-height: 100vh; background: var(--bg); }
    .d-header-title { font-weight: 600; font-size: 14px; }
    .d-layout { display: flex; align-items: flex-start; }
    .d-nav { position: sticky; top: 56px; width: 230px; flex: none; height: calc(100vh - 56px); overflow: auto; padding: 18px 12px; border-inline-end: 1px solid var(--border); display: grid; align-content: start; gap: 2px; }
    .d-nav button { display: flex; align-items: center; justify-content: space-between; gap: 8px; height: 34px; padding-inline: 10px; border: 0; border-radius: 8px; background: transparent; color: var(--text-2); font-size: 13.5px; text-align: start; cursor: pointer; }
    .d-nav button:hover, .d-nav button[aria-current="true"] { background: var(--surface-2); color: var(--text); }
    .d-nav .d-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); }
    .d-main { flex: 1; min-width: 0; padding: 28px 36px 120px; }
    .d-content { max-width: 860px; margin-inline: auto; display: grid; gap: 22px; }
    .d-section { display: grid; gap: 16px; padding: 22px; background: var(--surface); border: 1px solid var(--border); border-radius: 14px; scroll-margin-top: 72px; }
    .d-section > header { display: grid; gap: 4px; }
    .d-section h2 { margin: 0; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
    .d-section > header p { margin: 0; font-size: 13px; color: var(--text-2); }
    .d-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
    .d-badge { padding: 2px 7px; border-radius: 999px; background: color-mix(in oklab, var(--accent) 14%, transparent); color: var(--accent); font-size: 11px; font-weight: 600; }
    .d-switch { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 12px; border: 1px solid var(--border); border-radius: 10px; }
    .d-switch > span { display: grid; gap: 2px; font-size: 13.5px; font-weight: 500; }
    .d-switch input { width: 38px; height: 22px; flex: none; appearance: none; -webkit-appearance: none; border-radius: 999px; background: var(--border-strong); position: relative; cursor: pointer; transition: background .15s; }
    .d-switch input::after { content: ""; position: absolute; top: 3px; inset-inline-start: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0, 0, 0, .2); }
    .d-switch input:checked { background: var(--accent); }
    .d-switch input:checked::after { transform: translateX(16px); }
    [dir="rtl"] .d-switch input:checked::after { transform: translateX(-16px); }
    .d-switch input:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

    /* Sortable lists */
    .d-list { display: grid; gap: 8px; }
    .d-item { display: flex; align-items: flex-start; gap: 8px; padding: 10px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); min-width: 0; }
    .d-item.is-dragging { opacity: .45; }
    .d-item.drop-before { box-shadow: 0 -3px 0 0 var(--accent); }
    .d-item.drop-after { box-shadow: 0 3px 0 0 var(--accent); }
    .d-item-body { flex: 1; min-width: 0; display: grid; gap: 8px; }
    .d-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; align-items: end; }
    .d-drag { flex: none; width: 28px; height: 34px; display: grid; place-items: center; border: 0; border-radius: 6px; background: transparent; color: var(--text-3); cursor: grab; touch-action: none; }
    .d-drag:hover { background: var(--surface-2); color: var(--text); }
    .d-drag:active { cursor: grabbing; }
    .d-icon-btn { flex: none; width: 34px; height: 34px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text-2); cursor: pointer; }
    .d-icon-btn:hover { color: var(--text); border-color: var(--border-strong); }
    .d-icon-btn.is-danger:hover { color: var(--err-fg); border-color: color-mix(in oklab, var(--err-fg) 40%, var(--border)); }
    .d-radio { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-2); white-space: nowrap; cursor: pointer; }
    .d-radio input { accent-color: var(--accent); }

    /* Endpoints */
    .d-tag { border: 1px solid var(--border); border-radius: 12px; background: var(--surface); overflow: hidden; }
    .d-tag.drop-before { box-shadow: 0 -3px 0 0 var(--accent); }
    .d-tag.drop-after { box-shadow: 0 3px 0 0 var(--accent); }
    .d-tag.is-dragging { opacity: .45; }
    .d-tag-head { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: var(--surface-2); border-bottom: 1px solid var(--border); }
    .d-tag-head strong { font-size: 14px; }
    .d-ops { display: grid; }
    .d-op { display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-top: 1px solid var(--border); background: var(--surface); }
    .d-op:first-child { border-top: 0; }
    .d-op.is-hidden { background: var(--surface-2); }
    .d-op.is-hidden .d-op-text { opacity: .55; text-decoration: line-through; }
    .d-op.drop-before { box-shadow: inset 0 3px 0 0 var(--accent); }
    .d-op.drop-after { box-shadow: inset 0 -3px 0 0 var(--accent); }
    .d-op.is-dragging { opacity: .45; }
    .d-op-text { flex: 1; min-width: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; }
    .d-op-text .g4t-method { min-width: 54px; }
    .d-op-path { font: 500 13px/1.3 var(--mono); word-break: break-all; }
    .d-op-summary { font-size: 13px; color: var(--text-2); }

    .d-savebar { position: fixed; inset-inline: 0; bottom: 0; z-index: 45; display: flex; justify-content: center; padding: 12px 16px; pointer-events: none; }
    .d-savebar-inner { pointer-events: auto; display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; padding: 10px 12px 10px 16px; background: var(--text); color: var(--bg); border-radius: 14px; box-shadow: var(--shadow); max-width: 860px; width: 100%; animation: g4tfade .16s ease-out; }
    .d-savebar-inner span { flex: 1; font-size: 13.5px; font-weight: 500; min-width: 180px; }
    .d-savebar .g4t-btn { border-color: transparent; }
    .d-savebar .d-discard { background: transparent; color: var(--bg); border: 1px solid color-mix(in oklab, var(--bg) 35%, transparent); }
    .d-alert { padding: 12px 14px; border-radius: 10px; background: var(--err-bg); color: var(--err-fg); font-size: 13px; display: grid; gap: 4px; }
    .d-note { padding: 12px 14px; border-radius: 10px; background: var(--surface-2); border: 1px solid var(--border); font-size: 13px; color: var(--text-2); }

    @media (max-width: 899px) {
        .d-nav { display: none; }
        .d-main { padding: 22px 16px 120px; }
    }
    @media (max-width: 559px) {
        .d-section { padding: 16px; }
    }
</style>
