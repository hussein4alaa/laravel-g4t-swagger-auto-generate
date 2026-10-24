<style>
    :root {
        color-scheme: light;
        --bg: #fafaf9; --surface: #ffffff; --surface-2: #f4f4f2; --border: #e7e6e3; --border-strong: #d6d5d1;
        --text: #1a1a1d; --text-2: #4b4b52; --text-3: #6b6b73; --code-bg: #f7f7f5; --accent: #4f46e5; --accent-fg: #ffffff;
        --m-get-fg: #1d4ed8; --m-get-bg: #eef3ff; --m-post-fg: #15803d; --m-post-bg: #eaf8ef; --m-put-fg: #a85a07; --m-put-bg: #fdf3e3;
        --m-patch-fg: #0f766e; --m-patch-bg: #e6f6f3; --m-delete-fg: #b91c1c; --m-delete-bg: #fdeeee;
        --ok-fg: #15803d; --ok-bg: #eaf8ef; --warn-fg: #a85a07; --warn-bg: #fdf3e3; --err-fg: #b91c1c; --err-bg: #fdeeee; --neu-fg: #4b4b52; --neu-bg: #f0f0ee;
        --j-str: #0b7a52; --j-num: #a3470a; --j-kw: #6d28d9; --j-key: #1a1a1d; --j-punc: #6b6b73;
        --shadow: 0 1px 2px rgba(16, 16, 20, .04), 0 10px 28px rgba(16, 16, 20, .09); --scrim: rgba(20, 20, 24, .42);
        --op-py: 12px; --sec-gap: 44px;
        --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    }

    :root[data-mode="dark"] {
        color-scheme: dark;
        --bg: #0f1012; --surface: #16171a; --surface-2: #1c1d21; --border: #27282d; --border-strong: #383a40;
        --text: #ededef; --text-2: #b4b4bb; --text-3: #8e8e97; --code-bg: #121316;
        --m-get-fg: #8fb6ff; --m-get-bg: rgba(96, 140, 255, .14); --m-post-fg: #74dca0; --m-post-bg: rgba(52, 199, 110, .13);
        --m-put-fg: #f4b86a; --m-put-bg: rgba(240, 160, 60, .13); --m-patch-fg: #6ad8c6; --m-patch-bg: rgba(40, 190, 170, .13);
        --m-delete-fg: #f59a9a; --m-delete-bg: rgba(240, 90, 90, .14);
        --ok-fg: #74dca0; --ok-bg: rgba(52, 199, 110, .13); --warn-fg: #f4b86a; --warn-bg: rgba(240, 160, 60, .13);
        --err-fg: #f59a9a; --err-bg: rgba(240, 90, 90, .14); --neu-fg: #b4b4bb; --neu-bg: rgba(255, 255, 255, .06);
        --j-str: #8ee2b0; --j-num: #f7b77a; --j-kw: #c6b2ff; --j-key: #ededef; --j-punc: #8e8e97;
        --shadow: 0 1px 2px rgba(0, 0, 0, .3), 0 14px 36px rgba(0, 0, 0, .5); --scrim: rgba(0, 0, 0, .6);
    }

    :root[data-density="compact"] { --op-py: 7px; --sec-gap: 28px; }

    *, *::before, *::after { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, -apple-system, "Segoe UI", "Noto Sans", "Noto Sans Arabic", sans-serif; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
    a { color: var(--accent); text-decoration: none; }
    a:hover { text-decoration: underline; }
    button, input, select, textarea { font: inherit; }
    :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
    ::placeholder { color: var(--text-3); }
    [hidden] { display: none !important; }
    .g4t-mono { font-family: var(--mono); }
    .g4t-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    @keyframes g4tspin { to { transform: rotate(360deg); } }
    @keyframes g4tfade { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: none; } }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .001ms !important; transition-duration: .001ms !important; } }

    .g4t-app { min-height: 100vh; background: var(--bg); color: var(--text); }

    /* Header */
    .g4t-header { position: sticky; top: 0; z-index: 40; height: 56px; display: flex; align-items: center; gap: 10px; padding-inline: 16px; background: color-mix(in oklab, var(--bg) 88%, transparent); backdrop-filter: saturate(1.4) blur(10px); -webkit-backdrop-filter: saturate(1.4) blur(10px); border-bottom: 1px solid var(--border); }
    .g4t-icon-btn { width: 32px; height: 32px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); cursor: pointer; flex: none; padding: 0; }
    .g4t-icon-btn:hover { border-color: var(--border-strong); }
    .g4t-drawer-btn { width: 36px; height: 36px; display: none; }
    .g4t-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
    .g4t-logo { width: 28px; height: 28px; flex: none; border-radius: 7px; background: var(--text); color: var(--bg); display: grid; place-items: center; font: 600 11px/1 var(--mono); letter-spacing: -.04em; }
    .g4t-brand-text { min-width: 0; line-height: 1.2; }
    .g4t-brand-name { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .g4t-brand-sub { font-size: 11.5px; color: var(--text-3); white-space: nowrap; }
    .g4t-divider { width: 1px; height: 24px; background: var(--border); margin-inline: 6px; flex: none; }
    .g4t-spacer { flex: 1; }
    .g4t-select-wrap { position: relative; display: inline-flex; align-items: center; flex: none; max-width: 100%; }
    .g4t-select { appearance: none; -webkit-appearance: none; height: 32px; padding-inline: 10px 28px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 13px; font-weight: 500; cursor: pointer; max-width: 100%; }
    .g4t-select-chev { position: absolute; inset-inline-end: 9px; pointer-events: none; color: var(--text-3); }
    .g4t-pop { position: relative; flex: none; }

    .g4t-auth-btn { height: 32px; display: inline-flex; align-items: center; gap: 8px; padding-inline: 10px 12px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text); font-size: 13px; font-weight: 500; cursor: pointer; }
    .g4t-auth-btn:hover { border-color: var(--border-strong); }
    .g4t-auth-btn.is-authenticated { padding-inline: 10px; border-color: color-mix(in oklab, var(--ok-fg) 35%, var(--border)); background: var(--ok-bg); color: var(--ok-fg); font-weight: 600; }
    .g4t-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--text-3); flex: none; }
    .is-authenticated .g4t-dot { background: var(--ok-fg); box-shadow: 0 0 0 3px color-mix(in oklab, var(--ok-fg) 22%, transparent); }
    .g4t-auth-sep { width: 1px; height: 14px; background: var(--border); }
    .g4t-auth-cta { display: inline-flex; align-items: center; gap: 5px; font-weight: 600; }
    .g4t-auth-text-out { display: inline-flex; align-items: center; gap: 8px; }

    .g4t-menu { position: absolute; top: calc(100% + 8px); inset-inline-end: 0; width: 300px; max-width: calc(100vw - 24px); background: var(--surface); border: 1px solid var(--border); border-radius: 12px; box-shadow: var(--shadow); padding: 14px; display: grid; gap: 12px; z-index: 50; animation: g4tfade .14s ease-out; }
    .g4t-appear-menu { width: 280px; gap: 14px; }
    .g4t-menu-label { font-size: 12px; font-weight: 600; color: var(--text-2); }
    .g4t-menu-hint { font-size: 11.5px; color: var(--text-3); }
    .g4t-token-box { font: 12.5px/1.4 var(--mono); padding: 8px 10px; border-radius: 8px; background: var(--code-bg); border: 1px solid var(--border); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .g4t-seg { display: grid; gap: 2px; padding: 2px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; }
    .g4t-seg button { height: 28px; border: 0; border-radius: 6px; background: transparent; color: var(--text-2); font-size: 12.5px; font-weight: 500; cursor: pointer; }
    .g4t-seg button[aria-checked="true"], .g4t-seg button[aria-selected="true"] { background: var(--surface); color: var(--text); box-shadow: 0 1px 2px rgba(0, 0, 0, .08), 0 0 0 1px var(--border); }
    .g4t-theme-list { display: grid; gap: 1px; max-height: 232px; overflow: auto; margin-inline: -6px; }
    .g4t-theme-list button { display: flex; align-items: center; gap: 10px; height: 32px; padding-inline: 8px; border: 0; border-radius: 7px; background: transparent; color: var(--text); font: 13px/1 var(--mono); text-align: start; cursor: pointer; }
    .g4t-theme-list button:hover, .g4t-theme-list button[aria-checked="true"] { background: var(--surface-2); }
    .g4t-swatch { width: 14px; height: 14px; border-radius: 4px; flex: none; border: 1px solid var(--border); }
    .g4t-theme-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .g4t-check { color: var(--accent); font-family: system-ui, sans-serif; font-size: 13px; }

    .g4t-btn { height: 32px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding-inline: 11px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 12.5px; font-weight: 500; cursor: pointer; white-space: nowrap; }
    .g4t-btn:hover { background: var(--surface-2); }
    .g4t-btn-danger { color: var(--err-fg); }
    .g4t-btn-danger:hover { background: var(--err-bg); }
    .g4t-btn-primary { height: 34px; padding-inline: 16px; border: 0; background: var(--accent); color: var(--accent-fg); font-size: 13px; font-weight: 600; }
    .g4t-btn-primary:hover { background: var(--accent); filter: brightness(1.06); }
    .g4t-btn-primary[disabled] { opacity: .7; cursor: wait; }
    .g4t-btn-ghost { height: 34px; border: 0; background: transparent; color: var(--text-2); }
    .g4t-btn-ghost:hover { background: transparent; color: var(--text); }
    .g4t-link-btn { padding: 0; border: 0; background: none; color: var(--accent); font: inherit; font-weight: 600; cursor: pointer; }
    .g4t-spinner { width: 12px; height: 12px; border-radius: 50%; border: 2px solid currentColor; border-right-color: transparent; display: inline-block; animation: g4tspin .7s linear infinite; }

    /* Layout */
    .g4t-body { display: flex; align-items: flex-start; min-width: 0; }
    .g4t-scrim { position: fixed; inset: 56px 0 0 0; background: var(--scrim); z-index: 24; }
    .g4t-aside { position: sticky; top: 56px; inset-inline-start: 0; height: calc(100vh - 56px); width: 280px; max-width: 86vw; flex: none; overflow-y: auto; padding: 16px 12px 40px; background: var(--bg); border-inline-end: 1px solid var(--border); z-index: 25; transition: transform .2s ease; }
    .g4t-filter { position: relative; margin-bottom: 14px; }
    .g4t-filter svg { position: absolute; inset-inline-start: 10px; top: 9px; color: var(--text-3); }
    .g4t-filter input { width: 100%; height: 32px; padding-inline: 30px 34px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 13px; }
    .g4t-filter kbd { position: absolute; inset-inline-end: 8px; top: 7px; min-width: 18px; height: 18px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 4px; font: 11px/1 var(--mono); color: var(--text-3); background: var(--surface-2); }
    #g4t-nav { display: grid; gap: 18px; }
    .g4t-nav-empty { padding: 8px; font-size: 13px; color: var(--text-3); }
    .g4t-nav-group { display: grid; gap: 1px; }
    .g4t-nav-tag { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 4px 8px 6px; border: 0; background: transparent; color: var(--text-3); font-size: 11.5px; font-weight: 600; line-height: 1.2; letter-spacing: .04em; text-transform: uppercase; cursor: pointer; text-align: start; }
    .g4t-nav-tag span:last-child { font-weight: 500; letter-spacing: 0; }
    .g4t-nav-op { display: flex; align-items: center; gap: 10px; min-height: 30px; padding: 5px 8px; border: 0; border-radius: 7px; background: transparent; color: var(--text-2); font-size: 13px; line-height: 1.3; text-align: start; cursor: pointer; width: 100%; }
    .g4t-nav-op:hover, .g4t-nav-op[aria-current="true"] { background: var(--surface-2); color: var(--text); }
    .g4t-nav-method { width: 34px; flex: none; font: 600 10.5px/1 var(--mono); }
    .g4t-nav-summary { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .g4t-main { flex: 1; min-width: 0; padding: 36px 40px 64px; }
    .g4t-content { max-width: 940px; margin-inline: auto; }

    /* Info */
    .g4t-info { display: grid; gap: 10px; padding-bottom: 8px; }
    .g4t-badges { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 12px; color: var(--text-3); }
    .g4t-badge { padding: 3px 7px; border-radius: 6px; background: var(--surface-2); border: 1px solid var(--border); font: 500 11.5px/1 var(--mono); color: var(--text-2); }
    .g4t-info h1 { margin: 0; font-size: 28px; line-height: 1.2; font-weight: 650; letter-spacing: -.02em; text-wrap: balance; }
    .g4t-info-desc { margin: 0; max-width: 64ch; color: var(--text-2); text-wrap: pretty; }
    .g4t-info-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin-top: 6px; font-size: 13px; color: var(--text-2); }
    .g4t-info-meta > span { display: inline-flex; align-items: center; gap: 8px; }
    .g4t-info-meta .g4t-select { height: 28px; padding-inline: 9px 28px; border-radius: 7px; font: 12.5px/1 var(--mono); }
    .g4t-muted { color: var(--text-3); }

    /* States */
    .g4t-state { margin-top: 36px; border-radius: 14px; padding: 28px; display: grid; gap: 16px; }
    .g4t-state-error { border: 1px solid var(--border); background: var(--surface); }
    .g4t-state-empty { border: 1px dashed var(--border-strong); padding: 40px 28px; justify-items: center; gap: 12px; text-align: center; }
    .g4t-state-nomatch { border: 1px dashed var(--border-strong); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
    .g4t-state h2 { margin: 0; font-size: 18px; font-weight: 600; }
    .g4t-state p { margin: 0; color: var(--text-2); max-width: 60ch; text-wrap: pretty; }
    .g4t-state-icon { width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; }
    .g4t-state-error .g4t-state-icon { background: var(--err-bg); color: var(--err-fg); }
    .g4t-state-empty .g4t-state-icon { background: var(--surface-2); color: var(--text-3); font: 600 13px/1 var(--mono); }
    .g4t-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .g4t-loading { margin-top: 36px; display: flex; align-items: center; gap: 10px; color: var(--text-3); }

    /* Sections & operations */
    .g4t-section { margin-top: var(--sec-gap); scroll-margin-top: 72px; }
    .g4t-section-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
    .g4t-section-head h2 { margin: 0; font-size: 17px; font-weight: 600; letter-spacing: -.01em; }
    .g4t-section-head span { color: var(--text-3); font-size: 13px; flex: 1; min-width: 0; }
    .g4t-op-list { display: grid; gap: 8px; margin-top: 12px; }
    .g4t-op { scroll-margin-top: 72px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); min-width: 0; }
    .g4t-op.is-open { border-color: var(--border-strong); box-shadow: var(--shadow); }
    .g4t-op-head { width: 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; padding: var(--op-py) 14px; border: 0; border-radius: 10px; background: transparent; color: inherit; text-align: start; cursor: pointer; }
    .g4t-method { min-width: 60px; text-align: center; padding: 5px 0; border-radius: 6px; font: 600 11px/1 var(--mono); }
    .g4t-path { font: 500 13.5px/1.3 var(--mono); color: var(--text); word-break: break-all; }
    .g4t-op-summary { flex: 1 1 180px; min-width: 0; color: var(--text-2); font-size: 13.5px; }
    .g4t-op-tail { display: inline-flex; align-items: center; gap: 10px; margin-inline-start: auto; }
    .g4t-lock { display: inline-flex; color: var(--text-3); }
    .g4t-lock.is-ready { color: var(--ok-fg); }
    .g4t-chev { color: var(--text-3); transition: transform .15s ease; }
    .is-open .g4t-chev { transform: rotate(180deg); }
    .g4t-deprecated .g4t-path { text-decoration: line-through; }
    .g4t-op-body { border-top: 1px solid var(--border); padding: 18px 16px 20px; display: grid; gap: 22px; min-width: 0; }
    .g4t-op-intro { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 12px; }
    .g4t-op-desc { margin: 0; flex: 1 1 280px; color: var(--text-2); max-width: 68ch; text-wrap: pretty; white-space: pre-line; }
    .g4t-try-toggle { height: 32px; padding-inline: 12px; border: 1px solid var(--text); border-radius: 8px; background: var(--text); color: var(--bg); font-size: 12.5px; font-weight: 600; cursor: pointer; }
    .g4t-try-toggle[aria-pressed="true"] { border-color: var(--border); background: var(--surface); color: var(--text); }
    .g4t-block { display: grid; gap: 8px; min-width: 0; }
    .g4t-block h3 { margin: 0; font-size: 13px; font-weight: 600; }
    .g4t-block-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
    .g4t-ctype { display: flex; gap: 2px; padding: 2px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 7px; }
    .g4t-ctype button { height: 24px; padding-inline: 8px; border: 0; border-radius: 5px; background: transparent; color: var(--text-2); font: 12px/1 var(--mono); cursor: pointer; }
    .g4t-ctype button[aria-selected="true"] { background: var(--surface); color: var(--text); box-shadow: 0 1px 2px rgba(0, 0, 0, .08), 0 0 0 1px var(--border); }
    .g4t-table { display: grid; gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .g4t-row { display: grid; grid-template-columns: minmax(120px, 220px) minmax(0, 1fr); gap: 12px; padding: 9px 12px; background: var(--surface); }
    .g4t-row-name { min-width: 0; }
    .g4t-row-name.is-nested { padding-inline-start: 12px; border-inline-start: 2px solid var(--border); }
    .g4t-field { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px; }
    .g4t-field-name { font: 500 13px/1.4 var(--mono); word-break: break-all; }
    .is-nested .g4t-field-name { color: var(--text-2); }
    .g4t-req { font-size: 11px; color: var(--err-fg); }
    .g4t-type { font: 12px/1.4 var(--mono); color: var(--text-3); word-break: break-word; }
    .g4t-note { font-size: 13px; color: var(--text-2); min-width: 0; word-break: break-word; }

    .g4t-try { border: 1px solid var(--border); border-radius: 10px; background: var(--surface-2); padding: 16px; display: grid; gap: 14px; min-width: 0; }
    .g4t-try-title { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .g4t-try-title span { font: 12px var(--mono); color: var(--text-3); word-break: break-all; }
    .g4t-hint { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; background: var(--warn-bg); color: var(--warn-fg); font-size: 13px; }
    .g4t-hint span { flex: 1; min-width: 160px; }
    .g4t-hint button { height: 28px; padding-inline: 10px; border: 1px solid currentColor; border-radius: 7px; background: transparent; color: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; }
    .g4t-try-section { display: grid; gap: 8px; min-width: 0; }
    .g4t-try-label { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; font-size: 12px; font-weight: 600; color: var(--text-2); }
    .g4t-try-label .g4t-mono { font-weight: 400; }
    .g4t-try-note { margin-inline-start: auto; font-weight: 400; color: var(--text-3); }
    .g4t-form { display: grid; gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .g4t-form-row { display: grid; grid-template-columns: minmax(140px, 220px) minmax(0, 1fr); gap: 12px; align-items: start; padding: 8px 12px; background: var(--surface); }
    .g4t-form-label { display: grid; gap: 1px; min-width: 0; padding-top: 6px; cursor: default; }
    .g4t-form-row.is-nested .g4t-form-label { padding-inline-start: 12px; border-inline-start: 2px solid var(--border); }
    .g4t-form-group { display: flex; align-items: baseline; gap: 8px; padding: 9px 12px 3px; background: var(--surface); }
    .g4t-form-control { display: grid; gap: 4px; min-width: 0; }
    .g4t-form-control input:not([type="file"]), .g4t-form-control select { width: 100%; min-width: 0; height: 34px; padding-inline: 10px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface); color: var(--text); font: 13px/1 var(--mono); }
    .g4t-form-control input:focus, .g4t-form-control select:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px color-mix(in oklab, var(--accent) 18%, transparent); }
    .g4t-form-control select { appearance: auto; }
    .g4t-form-hint { font-size: 12px; color: var(--text-3); }
    .g4t-file { display: flex; align-items: center; gap: 10px; min-width: 0; min-height: 34px; }
    .g4t-file-btn { flex: none; position: relative; }
    .g4t-file-btn:focus-within { outline: 2px solid var(--accent); outline-offset: 2px; }
    .g4t-file-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font: 12.5px/1.3 var(--mono); color: var(--text); }
    .g4t-file-name.is-empty { color: var(--text-3); font-family: inherit; }
    .g4t-file-clear { flex: none; width: 26px; height: 26px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-2); font-size: 15px; line-height: 1; cursor: pointer; }
    .g4t-file-clear:hover { color: var(--err-fg); border-color: color-mix(in oklab, var(--err-fg) 40%, var(--border)); }
    /* Environments, download, code samples, history */
    .g4t-env-btn { height: 32px; display: inline-flex; align-items: center; gap: 7px; padding-inline: 10px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 13px; font-weight: 500; cursor: pointer; flex: none; max-width: 200px; }
    .g4t-env-btn:hover { border-color: var(--border-strong); }
    .g4t-env-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .g4t-count { min-width: 18px; height: 18px; padding-inline: 5px; display: inline-grid; place-items: center; border-radius: 999px; background: var(--surface-2); border: 1px solid var(--border); color: var(--text-2); font: 600 11px/1 var(--mono); }
    .g4t-download-menu { width: 230px; gap: 6px; }
    .g4t-download-menu .g4t-btn { justify-content: flex-start; }
    .g4t-env-dialog { width: min(520px, 100%); }
    .g4t-env-body { display: grid; gap: 14px; padding: 22px; max-height: calc(100vh - 48px); overflow: auto; }
    .g4t-env-body > p { margin: 0; font-size: 13px; color: var(--text-2); }
    .g4t-env-body code { font-size: 12px; padding: 1px 4px; border-radius: 4px; background: var(--surface-2); }
    .g4t-env-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
    .g4t-env-head h2 { margin: 0; font-size: 17px; font-weight: 600; }
    .g4t-env-body label { display: grid; gap: 5px; font-size: 13px; font-weight: 500; }
    .g4t-env-body input { height: 36px; padding-inline: 10px; border: 1px solid var(--border-strong); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 13.5px; min-width: 0; }
    .g4t-env-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
    .g4t-env-tab { height: 30px; display: inline-flex; align-items: center; gap: 7px; padding-inline: 11px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text-2); font-size: 12.5px; font-weight: 500; cursor: pointer; }
    .g4t-env-tab[aria-selected="true"] { border-color: var(--accent); color: var(--text); box-shadow: 0 0 0 3px color-mix(in oklab, var(--accent) 15%, transparent); }
    .g4t-env-add { border-style: dashed; }
    .g4t-dot-on { background: var(--ok-fg); }
    .g4t-var-row { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 3fr) 34px; gap: 8px; }
    .g4t-try-title { justify-content: space-between; }
    .g4t-try-tools { display: inline-flex; gap: 6px; margin-inline-start: auto; }
    .g4t-try-tools .g4t-btn { height: 28px; font-size: 12px; padding-inline: 9px; }
    .g4t-try-tools .g4t-btn[aria-pressed="true"] { background: var(--surface-2); border-color: var(--border-strong); }
    .g4t-history { display: grid; gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: 8px; overflow: hidden; }
    .g4t-history-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; padding: 8px 10px; background: var(--surface); }
    .g4t-history-url { flex: 1 1 200px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 12.5px; }
    .g4t-history-meta { font-size: 12px; }
    .g4t-history-actions { display: inline-flex; gap: 6px; }
    .g4t-history-actions .g4t-btn { height: 26px; font-size: 12px; padding-inline: 8px; }
    .g4t-history > .g4t-actions { padding: 6px 10px; background: var(--surface); }
    .g4t-history-empty { padding: 12px; border: 1px dashed var(--border-strong); border-radius: 8px; font-size: 13px; color: var(--text-3); }
    .g4t-code-panel { display: grid; gap: 8px; min-width: 0; }
    .g4t-code-panel .g4t-code { max-height: 360px; }
    .g4t-op-intro .g4t-btn[aria-pressed="true"] { background: var(--surface-2); border-color: var(--border-strong); }
    .g4t-items { display: grid; gap: 6px; justify-items: start; }
    .g4t-item { display: flex; align-items: center; gap: 8px; width: 100%; min-width: 0; }
    .g4t-item > input, .g4t-item > select { flex: 1; }
    .g4t-item > .g4t-file { flex: 1; }
    .g4t-item-remove { flex: none; width: 34px; height: 34px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 7px; background: var(--surface); color: var(--text-2); font-size: 16px; line-height: 1; cursor: pointer; }
    .g4t-item-remove:hover { color: var(--err-fg); border-color: color-mix(in oklab, var(--err-fg) 40%, var(--border)); }
    .g4t-add-item { height: 30px; font-size: 12.5px; }
    .g4t-inputs { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 10px; }
    .g4t-input { display: grid; gap: 4px; min-width: 0; }
    .g4t-input > span { font: 12px/1.3 var(--mono); color: var(--text-2); word-break: break-all; }
    .g4t-input > span em { font-style: normal; color: var(--text-3); }
    .g4t-input input, .g4t-input select { height: 32px; padding-inline: 10px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface); color: var(--text); font: 13px/1 var(--mono); min-width: 0; width: 100%; }
    .g4t-input input[type="file"] { padding: 5px 8px; height: auto; font-size: 12px; }
    .g4t-textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface); color: var(--text); font: 12.5px/1.6 var(--mono); resize: vertical; }
    .g4t-result { display: grid; gap: 10px; min-width: 0; animation: g4tfade .18s ease-out; }
    .g4t-status { padding: 3px 8px; border-radius: 999px; font: 600 12px/1.2 var(--mono); }
    .g4t-code-wrap { position: relative; min-width: 0; }
    .g4t-code { margin: 0; padding: 12px 14px; padding-inline-end: 44px; border-radius: 8px; background: var(--code-bg); border: 1px solid var(--border); font: 12.5px/1.6 var(--mono); overflow: auto; max-height: 320px; color: var(--text); white-space: pre; }
    .g4t-code.is-muted { color: var(--text-2); font-size: 12px; max-height: 220px; padding-inline-end: 14px; }
    .g4t-copy { position: absolute; top: 8px; inset-inline-end: 8px; width: 28px; height: 28px; display: grid; place-items: center; border: 1px solid var(--border); border-radius: 6px; background: var(--surface); color: var(--text-2); cursor: pointer; padding: 0; }
    .g4t-copy:hover { color: var(--text); }
    .g4t-status-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
    .g4t-status-tab { height: 30px; display: inline-flex; align-items: center; gap: 7px; padding-inline: 10px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text); font-size: 12.5px; cursor: pointer; }
    .g4t-status-tab .g4t-dot { background: currentColor; }
    .g4t-status-tab b { font-family: var(--mono); font-weight: 600; }
    .g4t-status-tab span:last-child { color: var(--text-2); }
    .g4t-ex-tabs { display: flex; flex-wrap: wrap; gap: 16px; border-bottom: 1px solid var(--border); }
    .g4t-ex-tabs button { padding: 6px 0 8px; margin-bottom: -1px; border: 0; border-bottom: 2px solid transparent; background: transparent; color: var(--text-2); font-size: 13px; font-weight: 500; line-height: 1.2; cursor: pointer; }
    .g4t-ex-tabs button[aria-selected="true"] { border-bottom-color: var(--accent); color: var(--text); }
    .g4t-view-toggle { margin-inline-start: auto; }
    .g4t-view-toggle button { height: 24px; padding-inline: 9px; font-size: 12px; }

    .g4t-ok { color: var(--ok-fg); background: var(--ok-bg); }
    .g4t-neu { color: var(--neu-fg); background: var(--neu-bg); }
    .g4t-warn { color: var(--warn-fg); background: var(--warn-bg); }
    .g4t-err { color: var(--err-fg); background: var(--err-bg); }
    .g4t-status-tab.g4t-ok, .g4t-status-tab.g4t-neu, .g4t-status-tab.g4t-warn, .g4t-status-tab.g4t-err { background: var(--surface); }
    .g4t-status-tab[aria-selected="true"].g4t-ok { background: var(--ok-bg); border-color: color-mix(in oklab, var(--ok-fg) 40%, var(--border)); }
    .g4t-status-tab[aria-selected="true"].g4t-neu { background: var(--neu-bg); border-color: color-mix(in oklab, var(--neu-fg) 40%, var(--border)); }
    .g4t-status-tab[aria-selected="true"].g4t-warn { background: var(--warn-bg); border-color: color-mix(in oklab, var(--warn-fg) 40%, var(--border)); }
    .g4t-status-tab[aria-selected="true"].g4t-err { background: var(--err-bg); border-color: color-mix(in oklab, var(--err-fg) 40%, var(--border)); }
    .g4t-status-tab b, .g4t-status-tab .g4t-dot { color: inherit; }
    .g4t-status-tab span:last-child { color: var(--text-2); }

    .m-get { color: var(--m-get-fg); background: var(--m-get-bg); }
    .m-post { color: var(--m-post-fg); background: var(--m-post-bg); }
    .m-put { color: var(--m-put-fg); background: var(--m-put-bg); }
    .m-patch { color: var(--m-patch-fg); background: var(--m-patch-bg); }
    .m-delete { color: var(--m-delete-fg); background: var(--m-delete-bg); }
    .g4t-nav-method.m-get, .g4t-nav-method.m-post, .g4t-nav-method.m-put, .g4t-nav-method.m-patch, .g4t-nav-method.m-delete { background: transparent; }

    .j-str { color: var(--j-str); } .j-num { color: var(--j-num); } .j-kw { color: var(--j-kw); } .j-key { color: var(--j-key); } .j-punc { color: var(--j-punc); }

    .g4t-footer { margin-top: 56px; padding-top: 16px; border-top: 1px solid var(--border); display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; font-size: 12px; color: var(--text-3); }
    .g4t-footer kbd { font: 11px var(--mono); padding: 1px 5px; border: 1px solid var(--border); border-radius: 4px; }

    /* Login dialog */
    .g4t-modal { position: fixed; inset: 0; z-index: 60; background: var(--scrim); display: grid; place-items: center; padding: 16px; }
    .g4t-login-dialog { width: min(380px, 100%); background: var(--surface); color: var(--text); border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow); animation: g4tfade .16s ease-out; }
    .g4t-login-dialog form { display: grid; gap: 14px; padding: 22px; }
    .g4t-login-dialog h2 { margin: 0; font-size: 17px; font-weight: 600; }
    .g4t-login-dialog p { margin: 0; font-size: 13px; color: var(--text-2); }
    .g4t-login-dialog label { display: grid; gap: 5px; font-size: 13px; font-weight: 500; }
    .g4t-login-dialog input { height: 36px; padding-inline: 10px; border: 1px solid var(--border-strong); border-radius: 8px; background: var(--surface); color: var(--text); font-size: 14px; }
    .g4t-login-dialog code { font-size: 12px; padding: 1px 4px; border-radius: 4px; background: var(--surface-2); }
    .g4t-token-field { display: flex; gap: 8px; }
    .g4t-token-field input { flex: 1; min-width: 0; font-size: 13px; }
    .g4t-token-field .g4t-btn { height: 36px; }
    .g4t-login-error { padding: 9px 11px; border-radius: 8px; background: var(--err-bg); color: var(--err-fg) !important; white-space: pre-line; }
    .g4t-login-dialog .g4t-actions { justify-content: flex-end; margin-top: 4px; }
    .g4t-login-dialog .g4t-btn, .g4t-login-dialog .g4t-btn-primary { height: 36px; font-size: 13.5px; padding-inline: 14px; }

    .g4t-toast-wrap { position: fixed; inset-inline: 0; bottom: 20px; display: flex; justify-content: center; pointer-events: none; z-index: 70; }
    .g4t-toast { padding: 9px 14px; border-radius: 999px; background: var(--text); color: var(--bg); font-size: 13px; font-weight: 500; line-height: 1.2; box-shadow: var(--shadow); animation: g4tfade .16s ease-out; }

    /* Responsive: drawer below 900px, compact chrome below 560px */
    @media (max-width: 899px) {
        .g4t-drawer-btn { display: grid; }
        .g4t-aside { position: fixed; transform: translateX(-100%); visibility: hidden; }
        [dir="rtl"] .g4t-aside { transform: translateX(100%); }
        .g4t-app.drawer-open .g4t-aside { transform: none; visibility: visible; box-shadow: var(--shadow); }
        .g4t-main { padding: 28px 24px 56px; }
    }

    @media (max-width: 559px) {
        .g4t-brand-sub, .g4t-auth-text, .g4t-auth-text-out, .g4t-env-name { display: none; }
        .g4t-var-row { grid-template-columns: minmax(0, 1fr) 34px; }
        .g4t-var-row input[data-bind="env-value"] { grid-column: 1; }
        .g4t-header { gap: 8px; padding-inline: 12px; }
        .g4t-brand-name { max-width: 28vw; }
        .g4t-divider { display: none; }
        .g4t-main { padding: 24px 16px 48px; }
        .g4t-row, .g4t-form-row { grid-template-columns: minmax(0, 1fr); gap: 4px; }
        .g4t-form-label { padding-top: 0; }
        .g4t-try-note { margin-inline-start: 0; width: 100%; }
        .g4t-info h1 { font-size: 23px; }
    }
</style>
