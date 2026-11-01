<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'NUR WinControl' }}</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #111827;
            --border: #334155;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #0284c7;
            --danger: #7f1d1d;
            --danger-border: #ef4444;
            --danger-text: #fecaca;
            --ok: #064e3b;
            --ok-border: #10b981;
            --ok-text: #d1fae5;
            --warn: #78350f;
            --warn-border: #f59e0b;
            --warn-text: #fde68a;
        }
        body { font-family: "Segoe UI", "IBM Plex Sans", sans-serif; margin: 0; background: var(--bg); color: var(--text); }
        a { color: #7dd3fc; text-decoration: none; }
        nav, main { max-width: 1280px; margin: 0 auto; padding: 1rem 1.25rem; }
        nav { display: flex; justify-content: space-between; align-items: center; }
        .card { background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .table { width: 100%; border-collapse: collapse; }
        .table th, .table td { border-bottom: 1px solid var(--border); padding: 0.75rem; text-align: left; vertical-align: top; }
        .button { background: var(--accent); color: white; border: 0; border-radius: 8px; padding: 0.7rem 1rem; cursor: pointer; display: inline-block; }
        .button-secondary { background: var(--border); }
        .button:disabled, .button[aria-disabled="true"] { opacity: 0.5; cursor: not-allowed; }
        .stack { display: grid; gap: 0.75rem; }
        .flash { background: var(--ok); border: 1px solid var(--ok-border); color: var(--ok-text); padding: 0.9rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
        .flash-error { background: var(--danger); border: 1px solid var(--danger-border); color: var(--danger-text); padding: 0.9rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
        .flash-warn { background: var(--warn); border: 1px solid var(--warn-border); color: var(--warn-text); padding: 0.9rem 1rem; border-radius: 10px; margin-bottom: 1rem; }
        .error { color: #fca5a5; font-size: 0.95rem; }
        input, select, textarea { width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid #475569; background: #0b1120; color: var(--text); box-sizing: border-box; }
        textarea { min-height: 8rem; resize: vertical; }
        pre { overflow: auto; background: #020617; padding: 1rem; border-radius: 10px; }
        .muted { color: var(--muted); }
        form.inline { display: inline; }
        .badge { display: inline-block; padding: 0.15rem 0.55rem; border-radius: 999px; font-size: 0.8rem; border: 1px solid var(--border); }
        .badge-error { border-color: var(--danger-border); color: var(--danger-text); }
        .badge-ok { border-color: var(--ok-border); color: var(--ok-text); }
        .badge-warn { border-color: var(--warn-border); color: var(--warn-text); }
        .workspace {
            position: relative;
            min-height: 70vh;
            border: 1px solid var(--border);
            border-radius: 12px;
            background:
                linear-gradient(180deg, rgba(2,132,199,0.08), transparent 40%),
                #0b1220;
            overflow: hidden;
        }
        .win {
            position: absolute;
            min-width: 280px;
            min-height: 160px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .win-title {
            cursor: move;
            user-select: none;
            padding: 0.55rem 0.75rem;
            background: #0b1628;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            gap: 0.5rem;
            align-items: center;
        }
        .win-body { padding: 0.75rem; overflow: auto; flex: 1; }
        .win-resize {
            position: absolute;
            right: 0;
            bottom: 0;
            width: 16px;
            height: 16px;
            cursor: nwse-resize;
            background: linear-gradient(135deg, transparent 50%, #475569 50%);
        }
        .safe-mode-banner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .countdown { font-variant-numeric: tabular-nums; font-size: 1.25rem; color: var(--warn-text); }
        .toolbar { display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.75rem; }
        .stale { opacity: 0.7; outline: 1px dashed var(--warn-border); }
        .loading-overlay {
            position: absolute; inset: 0; background: rgba(15,23,42,0.55);
            display: none; align-items: center; justify-content: center; z-index: 50;
        }
        .loading-overlay.is-active { display: flex; }
    </style>
</head>
<body>
    @auth
        <nav>
            <div>
                <strong>NUR WinControl</strong>
                <span class="muted">Isolated namespace orchestration</span>
            </div>
            <div style="display: flex; gap: 1rem; align-items: center;">
                <a href="{{ route('dashboard') }}">Dashboard</a>
                <a href="{{ route('routers.index') }}">Routers</a>
                <a href="{{ route('licensing.admin') }}">Licensing</a>
                <form class="inline" method="POST" action="{{ route('logout') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button button-secondary">Logout</button>
                </form>
            </div>
        </nav>
    @endauth

    <main>
        @if (session('status'))
            <div class="flash" role="status">{{ session('status') }}</div>
        @endif

        @if (session('error'))
            <div class="flash-error" role="alert">
                <strong>Connection / appliance error</strong>
                @if (session('error_kind'))
                    <span class="badge badge-error">{{ session('error_kind') }}</span>
                @endif
                <div>{{ session('error') }}</div>
                @if (session('staged_preserved'))
                    <div class="muted" style="margin-top: 0.5rem;">Staged desired configuration was preserved. Reconnect and retry apply when the appliance is reachable.</div>
                @endif
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
