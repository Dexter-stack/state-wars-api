<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>State Wars API</title>
    <style>
        :root {
            --bg: #0b1f14; --card: #11301f; --line: #1f4a31; --text: #eef7f1; --muted: #9dbcaa;
            --green: #2fbf71; --amber: #f2b632; --red: #ef6461;
        }
        @media (prefers-color-scheme: light) {
            :root { --bg: #f3f8f4; --card: #ffffff; --line: #d6e6db; --text: #0f2a1b; --muted: #557262; }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px;
            background: var(--bg); color: var(--text);
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }
        main { width: 100%; max-width: 560px; }
        .flag { display: flex; height: 6px; border-radius: 3px; overflow: hidden; margin-bottom: 28px; }
        .flag span { flex: 1; background: var(--green); }
        .flag span:nth-child(2) { background: #fff; }
        h1 { margin: 0 0 6px; font-size: 34px; letter-spacing: -0.02em; }
        .tag { margin: 0 0 28px; color: var(--muted); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 20px; }
        .status { display: flex; align-items: center; gap: 12px; font-weight: 600; }
        .dot { width: 12px; height: 12px; border-radius: 50%; background: var(--amber); flex: none; }
        .dot.ok { background: var(--green); }
        .dot.down { background: var(--red); }
        .detail { margin: 6px 0 0 24px; color: var(--muted); font-size: 14px; }
        dl { margin: 20px 0 0; padding-top: 16px; border-top: 1px solid var(--line); display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 14px; }
        dt { color: var(--muted); }
        dd { margin: 0; text-align: right; }
        code { font: 13px ui-monospace, SFMono-Regular, Consolas, monospace; }
        a { color: var(--green); }
        footer { margin-top: 20px; color: var(--muted); font-size: 14px; }
    </style>
</head>
<body>
<main>
    <div class="flag" aria-hidden="true"><span></span><span></span><span></span></div>
    <h1>State Wars</h1>
    <p class="tag">The API behind the nightly 8pm quiz, where Nigerians play for their state.</p>

    <section class="card" aria-live="polite">
        <div class="status"><span class="dot" id="dot"></span><span id="status">Checking the service…</span></div>
        <p class="detail" id="detail">This page is served by the API, so the API itself is running.</p>
        <dl>
            <dt>Battle time</dt><dd>Every night, 20:00 WAT</dd>
            <dt>API base</dt><dd><code>/v1</code></dd>
            <dt>Health check</dt><dd><a href="/health"><code>/health</code></a></dd>
        </dl>
    </section>

    <footer>
        This address is for the app, not for people.
        @if ($webUrl !== '')
            To play, go to <a href="{{ $webUrl }}">{{ parse_url($webUrl, PHP_URL_HOST) ?: $webUrl }}</a>.
        @endif
    </footer>
</main>
<script>
    // /ready answers 200 when the API can reach its database and cache, 503 when it cannot.
    (function () {
        var dot = document.getElementById('dot'), status = document.getElementById('status'), detail = document.getElementById('detail');
        function show(cls, title, text) { dot.className = 'dot ' + cls; status.textContent = title; detail.textContent = text; }
        fetch('/ready', { cache: 'no-store' }).then(function (r) {
            if (r.ok) { show('ok', 'All systems ready', 'The API, its database and its cache are all answering.'); }
            else { show('down', 'Running, but not ready', 'The API is up but cannot reach its database or cache right now.'); }
        }).catch(function () {
            show('down', 'Could not check', 'The readiness check did not answer. Try again in a moment.');
        });
    })();
</script>
</body>
</html>
