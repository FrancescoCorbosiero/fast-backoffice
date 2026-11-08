<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#18181b">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Area clienti">
    <link rel="manifest" href="/portal/manifest.webmanifest">
    <link rel="icon" href="/portal/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/portal/icon.svg">
    <title>{{ $title ?? 'Area clienti' }}</title>
    <style>
        :root { --bg: #f4f4f5; --card: #fff; --text: #18181b; --muted: #71717a; --line: #e4e4e7; --accent: #18181b; --ok: #15803d; --warn: #b45309; --bad: #b91c1c; --info: #1d4ed8; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: var(--bg); color: var(--text); font-size: 16px; line-height: 1.5; padding-bottom: env(safe-area-inset-bottom); }
        header { background: var(--card); border-bottom: 1px solid var(--line); padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        header .brand { font-weight: 700; text-decoration: none; color: var(--text); }
        header nav { display: flex; gap: 12px; align-items: center; font-size: 14px; }
        header nav a, header nav button { color: var(--muted); text-decoration: none; background: none; border: 0; font: inherit; cursor: pointer; padding: 0; }
        main { max-width: 640px; margin: 0 auto; padding: 16px; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 12px; }
        h2 { font-size: 17px; margin: 0 0 8px; }
        p { margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 14px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input[type=email], select, textarea { width: 100%; font: inherit; padding: 12px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--text); }
        textarea { min-height: 160px; resize: vertical; }
        .field { margin-bottom: 16px; }
        .error { color: var(--bad); font-size: 14px; margin-top: 6px; }
        .btn { display: inline-block; width: 100%; text-align: center; font: inherit; font-weight: 600; padding: 14px 20px; border-radius: 10px; border: 0; cursor: pointer; text-decoration: none; background: var(--accent); color: #fff; }
        .btn.secondary { background: #fff; color: var(--text); border: 1px solid var(--line); }
        .btn.danger { background: var(--bad); }
        .btn + .btn, form + form { margin-top: 10px; }
        .list { list-style: none; margin: 0; padding: 0; }
        .list li { border-top: 1px solid var(--line); }
        .list li:first-child { border-top: 0; }
        .list a { display: block; padding: 14px 0; text-decoration: none; color: inherit; }
        .row { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; }
        .badge { display: inline-block; font-size: 13px; font-weight: 600; padding: 3px 10px; border-radius: 999px; background: #e4e4e7; color: var(--text); white-space: nowrap; }
        .badge.success { background: #dcfce7; color: var(--ok); }
        .badge.warning { background: #fef3c7; color: var(--warn); }
        .badge.danger { background: #fee2e2; color: var(--bad); }
        .badge.info { background: #dbeafe; color: var(--info); }
        .flash { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; }
        .request-text { white-space: pre-wrap; }
        .preview { width: 100%; height: 70vh; border: 1px solid var(--line); border-radius: 10px; background: #fff; }
        ul.paths { padding-left: 20px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
        input[type=file] { width: 100%; font: inherit; font-size: 15px; padding: 10px 0; }
        .hint { margin: 6px 0 0; }
        details.photos { margin-top: 12px; }
        details.photos summary { cursor: pointer; font-weight: 600; }
        details.photos ul { list-style: none; margin: 12px 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; }
        details.photos li { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        details.photos li .muted { font-size: 12px; overflow-wrap: anywhere; }
        details.photos img { width: 100%; height: 120px; object-fit: cover; border-radius: 8px; border: 1px solid var(--line); background: var(--bg); }
        .photo-gone { display: flex; align-items: center; justify-content: center; height: 120px; border-radius: 8px; border: 1px dashed var(--line); color: var(--muted); font-size: 13px; }
        @media (min-width: 640px) { .actions { display: flex; gap: 10px; } .actions form { flex: 1; margin: 0; } }
    </style>
</head>
<body>
    <header>
        <a class="brand" href="{{ route('portal.requests') }}">Area clienti</a>
        <nav>
            @if (($client ?? null) !== null)
                <a href="{{ route('portal.requests') }}">Le mie richieste</a>
                <a href="{{ route('portal.requests.create') }}">Nuova</a>
                <form method="post" action="{{ route('portal.sign-out') }}" style="margin:0">@csrf<button type="submit">Esci</button></form>
            @endif
        </nav>
    </header>
    <main>
        @if (! empty($status))
            <div class="flash">{{ $status }}</div>
        @endif
        {{ $slot ?? '' }}
        @yield('content')
    </main>
</body>
</html>
