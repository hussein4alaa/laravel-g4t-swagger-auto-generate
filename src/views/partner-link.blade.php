<!doctype html>
<html lang="en" data-mode="light">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $expired ? 'Link expired' : 'Link not valid' }} — {{ config('app.name') }} API</title>
    @include('swagger::partials.favicon')
    @include('swagger::g4t.styles')
    @include('swagger::dashboard.styles')
    <script>
        try { if (localStorage.getItem('g4t-ui:mode') === 'dark' || (!localStorage.getItem('g4t-ui:mode') && matchMedia('(prefers-color-scheme: dark)').matches)) { document.documentElement.dataset.mode = 'dark'; } } catch (e) { }
    </script>
    <style>
        .p-card { gap: 18px; }
        .p-icon { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 12px; background: var(--warn-bg); color: var(--warn-fg); }
        .p-title { margin: 0; font-size: 19px; font-weight: 650; letter-spacing: -.01em; }
        .p-text { margin: 0; font-size: 14px; line-height: 1.6; color: var(--text-2); }
        .p-ar { padding-top: 16px; border-top: 1px solid var(--border); font-family: system-ui, "Segoe UI", Tahoma, sans-serif; }
        .p-ar .p-title { font-size: 17px; letter-spacing: 0; }
    </style>
</head>

<body>
    <main class="d-login">
        <div class="d-login-card p-card" role="alert">
            <div class="d-login-brand">
                <span class="p-icon" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        @if ($expired)
                            <circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>
                        @else
                            <path d="M9 15 15 9"></path><path d="M11 6.5 12.5 5a4.95 4.95 0 0 1 7 7L18 13.5"></path><path d="M13 17.5 11.5 19a4.95 4.95 0 0 1-7-7L6 10.5"></path>
                        @endif
                    </svg>
                </span>
                <div>
                    <div class="d-login-title">{{ config('app.name') }} API</div>
                    <div class="g4t-muted" style="font-size:13px">Shared documentation link</div>
                </div>
            </div>

            <div style="display:grid;gap:8px">
                @if ($expired)
                    <h1 class="p-title">This link has expired</h1>
                    <p class="p-text">It stopped working on <b>{{ $expires }}</b>. Ask the person who shared it with you for a new link.</p>
                @else
                    <h1 class="p-title">This link is not valid</h1>
                    <p class="p-text">It may have been replaced with a new one, removed, or copied incompletely. Check that you have the whole address, or ask the person who shared it with you for a new link.</p>
                @endif
            </div>

            <div class="p-ar" dir="rtl" lang="ar" style="display:grid;gap:8px">
                @if ($expired)
                    <h2 class="p-title">انتهت صلاحية هذا الرابط</h2>
                    <p class="p-text">توقف عن العمل بتاريخ <b dir="ltr">{{ $expires }}</b>. اطلب رابطاً جديداً من الشخص الذي شاركه معك.</p>
                @else
                    <h2 class="p-title">هذا الرابط غير صالح</h2>
                    <p class="p-text">ربما استُبدل برابط جديد، أو حُذف، أو نُسخ بشكل ناقص. تأكد من أن العنوان كامل، أو اطلب رابطاً جديداً من الشخص الذي شاركه معك.</p>
                @endif
            </div>
        </div>
    </main>
</body>

</html>
