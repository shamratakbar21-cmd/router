<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'NUR WinControl' }}</title>
    <link rel="stylesheet" href="{{ asset('desktop/wincontrol.css') }}?v=20261104-desktop-2">
    @stack('styles')
</head>
<body class="{{ auth()->check() ? 'authenticated' : 'guest' }} {{ request()->routeIs('routers.show') ? 'router-desktop' : '' }}">
<svg class="icon-library" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><defs>
    <symbol id="i-router" viewBox="0 0 24 24"><path d="M3 8h18v12H3zM7 8V3m10 5V3M6 16h1m4 0h1m4 0h1"/></symbol>
    <symbol id="i-network" viewBox="0 0 24 24"><path d="M8 3h8v6H8zM3 17h6v5H3zm12 0h6v5h-6zM12 9v4H6v4m6-4h6v4"/></symbol>
    <symbol id="i-interfaces" viewBox="0 0 24 24"><path d="M2 4h20v15H2zM5 8h5v6H5zM14 8h5v6h-5zM6 19v3m12-3v3M7 1v3m10-3v3"/></symbol>
    <symbol id="i-bridge" viewBox="0 0 24 24"><path d="M7 1v22M3 5h8M3 11h8M3 17h8M17 1v22m-4-18h8m-8 6h8m-8 6h8M7 8h10m-10 6h10"/></symbol>
    <symbol id="i-ppp" viewBox="0 0 24 24"><path d="M3 2v17h7M1 6h5m-5 6h5M15 2h7v7h-7zm0 13h7v7h-7zM10 5h5M10 5v14h5"/></symbol>
    <symbol id="i-arp" viewBox="0 0 24 24"><path d="M2 8h20M5 2v6m7-6v6m7-6v6M7 15h10m-8-3-3 3 3 3m6-6 3 3-3 3M12 18v4"/></symbol>
    <symbol id="i-switch" viewBox="0 0 24 24"><path d="M2 3h20v6H2zM4 9v12m5-12v12m6-12v12m5-12v12M1 21h5m12 0h5M6 6h1m4 0h1m4 0h1"/></symbol>
    <symbol id="i-mesh" viewBox="0 0 24 24"><circle cx="4" cy="4" r="2"/><circle cx="20" cy="5" r="2"/><circle cx="11" cy="20" r="2"/><circle cx="3" cy="16" r="2"/><path d="m6 4 12 1M5 6l5 12m8-11-6 11M4 6l-1 8m2 3 4 2M5 15 18 6"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 2 21 6v6c0 5-9 10-9 10S3 17 3 12V6zM12 6v11"/></symbol>
    <symbol id="i-list" viewBox="0 0 24 24"><path d="M6 4h13v17H6zM3 7h6m-6 5h6m-6 5h6m3-10h4m-4 5h4m-4 5h4"/></symbol>
    <symbol id="i-route" viewBox="0 0 24 24"><path d="m3 4 5 4-5 4m5-4h5c4 0 2 10 7 10m-4-4 4 4-4 4M3 20l5-4-5-4"/></symbol>
    <symbol id="i-folder" viewBox="0 0 24 24"><path d="M2 6h8l2 3h10l-3 12H2zM2 16V3h8l2 3h8v3"/></symbol>
    <symbol id="i-terminal" viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="18" rx="2"/><path d="m6 8 4 4-4 4m7 0h5"/></symbol>
    <symbol id="i-settings" viewBox="0 0 24 24"><path d="m9 3 1-2h4l1 3 3 1 3-1 2 4-2 2v4l2 2-2 4-3-1-3 1-1 3h-4l-1-3-3-1-3 1-2-4 2-2v-4L1 8l2-4 3 1z"/><circle cx="12" cy="12" r="4"/></symbol>
    <symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2 8a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0m-11 4a5 5 0 0 1 8 0m-4 4h.01"/></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><path d="M3 3v18h19M6 16l4-6 4 3 7-9"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 5v7l5 3"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="10" cy="10" r="7"/><path d="m15 15 7 7"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 3v18M3 12h18"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 9A9 9 0 1 0 20 17M21 3v6h-6"/></symbol>
    <symbol id="i-filter" viewBox="0 0 24 24"><path d="M3 3h18l-7 9v7l-4 3V12z"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="m4 12 5 5L21 5"/></symbol>
    <symbol id="i-close" viewBox="0 0 24 24"><path d="m5 5 14 14M5 19 19 5"/></symbol>
    <symbol id="i-save" viewBox="0 0 24 24"><path d="M3 3h15l3 3v15H3zM7 3v7h10V3M7 21v-7h10v7"/></symbol>
</defs></svg>
@auth
    @php
        $shellRouter = $routerNode ?? request()->route('router');
        if (! $shellRouter instanceof \App\Models\RouterNode) {
            $shellRouter = auth()->user()->is_admin ? \App\Models\RouterNode::query()->first() : null;
        }
        $desktopUrl = $shellRouter ? route('routers.show', $shellRouter) : route('routers.index');
        $menu = [
            ['Quick Set', 'settings', 'overview'], ['WiFi', 'wifi', 'unsupported:WiFi'],
            ['Interfaces', 'interfaces', 'interfaces'], ['WireGuard', 'shield', 'wireguard'],
            ['Bridge', 'bridge', 'bridge'], ['PPP', 'ppp', 'pppoe'],
            ['Switch', 'switch', 'unsupported:Switch'], ['Mesh', 'mesh', 'unsupported:Mesh'],
        ];
    @endphp
    <aside class="sidebar" aria-label="Main menu">
        <a class="brand" href="{{ $desktopUrl }}"><svg class="brand-logo"><use href="#i-router"/></svg><span>NUR<span class="brand-light">ROUTER</span></span></a>
        <div class="sidebar-items">
            @foreach ($menu as [$label, $icon, $panel])
                <a class="menu-item" data-panel="{{ $panel }}" href="{{ $desktopUrl }}?panel={{ urlencode($panel) }}"><svg><use href="#i-{{ $icon }}"/></svg><span>{{ $label }}</span></a>
            @endforeach
            <details class="menu-group"><summary><svg><use href="#i-network"/></svg><span>IP</span><span class="chevron">›</span></summary>
                @foreach (['arp'=>'ARP', 'addresses'=>'Addresses', 'routes'=>'Routes', 'dhcp-servers'=>'DHCP Server', 'dhcp'=>'DHCP Leases', 'dns'=>'DNS', 'firewall'=>'Firewall', 'connections'=>'Connections'] as $panel=>$label)
                    <a data-panel="{{ $panel }}" href="{{ $desktopUrl }}?panel={{ $panel }}">{{ $label }}</a>
                @endforeach
            </details>
            <a class="menu-item" data-panel="ipv6" href="{{ $desktopUrl }}?panel=ipv6"><svg><use href="#i-network"/></svg><span>IPv6</span></a>
            <a class="menu-item" data-panel="unsupported:MPLS" href="{{ $desktopUrl }}?panel=unsupported:MPLS"><svg><use href="#i-network"/></svg><span>MPLS</span></a>
            <a class="menu-item" data-panel="routes" href="{{ $desktopUrl }}?panel=routes"><svg><use href="#i-route"/></svg><span>Routing</span><span class="chevron">›</span></a>
            <details class="menu-group"><summary><svg><use href="#i-settings"/></svg><span>System</span><span class="chevron">›</span></summary>
                <a data-panel="capabilities" href="{{ $desktopUrl }}?panel=capabilities">Feature Status</a>
                <a data-panel="resources" href="{{ $desktopUrl }}?panel=resources">Resources</a>
                <a data-panel="revisions" href="{{ $desktopUrl }}?panel=revisions">Revisions</a>
                @if ($shellRouter)<a href="{{ route('routers.edit', $shellRouter) }}">Identity &amp; connection</a><a href="{{ route('licensing.status', $shellRouter) }}">License</a>@endif
            </details>
            <a class="menu-item" data-panel="queues" href="{{ $desktopUrl }}?panel=queues"><svg><use href="#i-chart"/></svg><span>Queues</span></a>
            <a class="menu-item" data-panel="unsupported:Dot1X" href="{{ $desktopUrl }}?panel=unsupported:Dot1X"><svg><use href="#i-shield"/></svg><span>Dot1X</span></a>
            @if ($shellRouter)<a class="menu-item" href="{{ route('routers.backup.show', $shellRouter) }}"><svg><use href="#i-folder"/></svg><span>Files &amp; Backups</span></a>@endif
            <a class="menu-item" data-panel="systemlog" href="{{ $desktopUrl }}?panel=systemlog"><svg><use href="#i-list"/></svg><span>Log</span></a>
            <a class="menu-item" data-panel="audit" href="{{ $desktopUrl }}?panel=audit"><svg><use href="#i-list"/></svg><span>Audit Log</span></a>
            <a class="menu-item" data-panel="unsupported:Terminal" href="{{ $desktopUrl }}?panel=unsupported:Terminal"><svg><use href="#i-terminal"/></svg><span>New Terminal</span></a>
            @if ($shellRouter)<a class="menu-item" href="{{ route('routers.bras.edit', $shellRouter) }}"><svg><use href="#i-network"/></svg><span>RADIUS / BRAS</span></a>@endif
            <a class="menu-item" data-panel="diagnostics" href="{{ $desktopUrl }}?panel=diagnostics"><svg><use href="#i-chart"/></svg><span>Tools</span><span class="chevron">›</span></a>
            <div class="menu-divider"></div>
            <a class="menu-item" href="{{ route('routers.index') }}"><svg><use href="#i-router"/></svg><span>Router inventory</span></a>
        </div>
    </aside>
    <header class="desktop-header">
        <div class="caption"><svg><use href="#i-router"/></svg><span>{{ auth()->user()->name.'@'.($shellRouter?->appliance_host ?? 'localhost') }} — {{ $shellRouter?->name ?? 'NUR' }} · WinControl</span><span class="caption-right">NURROUTER OS</span></div>
        <div class="workspace-bar">
            @if(request()->routeIs('routers.show'))
            <label for="workspace-select">Workspace:</label><select id="workspace-select" aria-label="Workspace"><option value="own">&lt;own&gt;</option></select><button class="icon-button" id="workspace-manage" type="button" title="Manage saved workspaces" aria-label="Manage saved workspaces"><svg><use href="#i-save"/></svg></button><span id="workspace-status" class="workspace-save-status" role="status"></span>
            @else
            <a class="button" href="{{ $desktopUrl }}">Open desktop</a>
            @endif
            <span class="open-windows" title="Open windows"><svg><use href="#i-folder"/></svg><span id="window-count">1</span></span>
            <button class="icon-button" type="button" id="global-find" title="Find in table" aria-label="Find in table"><svg><use href="#i-search"/></svg></button>
            <span class="bar-spacer"></span><span class="version-label">NurRouterOS</span><span class="control-label">NUR WinControl</span>
            @if ($shellRouter)
                <span class="safe-mode-indicator" title="Configuration changes use timed Safe Mode rollback"><svg><use href="#i-shield"/></svg> Safe Mode<span class="safe-state">{{ $shellRouter->safe_mode_status === 'pending_confirm' ? 'pending' : 'ready' }}</span></span>
                <a class="icon-button" aria-label="Router settings" title="Router settings" href="{{ route('routers.edit', $shellRouter) }}"><svg><use href="#i-settings"/></svg></a>
            @endif
            <form class="inline logout-form" method="POST" action="{{ route('logout') }}">@csrf @method('DELETE')<button title="Sign out" aria-label="Sign out" class="icon-button">↪</button></form>
        </div>
    </header>
@endauth
<main class="app-main">
    @if(session('status'))<div class="flash" role="status">{{ session('status') }}</div>@endif
    @if(session('error'))<div class="flash-error" role="alert">{{ session('error') }} @if(session('staged_preserved')) Staged configuration has been preserved. @endif</div>@endif
    @yield('content')
</main>
@auth
<footer class="system-status"><span class="device-label"><svg><use href="#i-router"/></svg><strong>{{ $shellRouter?->name ?? 'NUR WinControl' }}</strong></span><span id="status-mac">{{ $shellRouter?->appliance_host }}</span><span class="status-separator"></span><span>x86_64</span><span id="status-memory">NUR RouterOS</span><span class="status-spacer"></span><span id="status-connection">{{ $shellRouter?->status ?? 'Ready' }}</span><svg><use href="#i-clock"/></svg><span id="status-uptime">—</span><time id="status-clock"></time></footer>
<script>setInterval(()=>{const el=document.getElementById('status-clock');if(el)el.textContent=new Date().toLocaleString(undefined,{hour12:false})},1000);</script>
@endauth
@stack('scripts')
</body>
</html>
