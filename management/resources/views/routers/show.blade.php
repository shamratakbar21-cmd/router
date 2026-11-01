@extends('layouts.app')

@section('content')
    @php
        $observed = $routerState['observed']['normalized'] ?? [
            'interfaces' => [], 'addresses' => [], 'routes' => [], 'bridge_vlans' => [],
            'firewall' => [], 'forwarding' => [], 'traffic' => [], 'dhcp_leases' => [],
            'pppoe_sessions' => [],
        ];
        $connectionState = session('connection_state', filled($routerNode->latestObservedState) ? 'connected' : 'unknown');
        $observedAgeSeconds = filled($routerNode->latestObservedState)
            ? now()->diffInSeconds($routerNode->latestObservedState->recorded_at)
            : null;
        $isStale = $observedAgeSeconds !== null && $observedAgeSeconds > 120;
        $safeModePending = $routerNode->safe_mode_status === 'pending_confirm' && $routerNode->safe_mode_confirm_by;
        $confirmByIso = $safeModePending ? $routerNode->safe_mode_confirm_by->toIso8601String() : null;
    @endphp

    <div class="card">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap;">
            <div>
                <h1>{{ $routerNode->name }}</h1>
                <p class="muted">{{ $routerNode->namespace_name }} | {{ $routerNode->management_interface }} | {{ $routerNode->management_cidr }}</p>
                <p class="muted">
                    Status: {{ $routerNode->status }}
                    | Lock version: {{ $routerNode->configuration_lock_version }}
                    | Desired revision: {{ $routerNode->desired_revision_id ?? 'none' }}
                    | Applied revision: {{ $routerNode->applied_revision_id ?? 'none' }}
                </p>
                <p>
                    Connection:
                    <span class="badge {{ $connectionState === 'disconnected' ? 'badge-error' : ($isStale ? 'badge-warn' : 'badge-ok') }}" id="connection-badge">
                        {{ $connectionState === 'disconnected' ? 'disconnected' : ($isStale ? 'stale-data' : $connectionState) }}
                    </span>
                    @if ($routerNode->hasStagedConfiguration())
                        <span class="badge badge-warn">staged revision preserved</span>
                    @endif
                </p>
            </div>
            <div class="toolbar">
                <a class="button" href="{{ route('routers.desired-state.edit', $routerNode) }}">Configure desired state</a>
                <a class="button button-secondary" href="{{ route('routers.state.show', $routerNode) }}?refresh=1" id="refresh-observe">Refresh observed state</a>
                <a class="button button-secondary" href="{{ route('licensing.status', $routerNode) }}">License</a>
                <a class="button button-secondary" href="{{ route('routers.backup.show', $routerNode) }}">Backup</a>
                <a class="button button-secondary" href="{{ route('routers.edit', $routerNode) }}">Edit router</a>
                <button type="button" class="button button-secondary" id="reset-layout">Reset window layout</button>
            </div>
        </div>

        @if ($safeModePending)
            <div class="flash-warn safe-mode-banner" style="margin-top: 1rem;">
                <div>
                    <strong>Safe Mode pending confirmation</strong>
                    <div class="muted">Automatic rollback unless confirmed before the deadline. Appliance watchdog does not require an active SSH session.</div>
                    <div class="countdown" id="safe-mode-countdown" data-deadline="{{ $confirmByIso }}">calculating…</div>
                </div>
                <form method="POST" action="{{ route('routers.safe-mode.confirm', $routerNode) }}">
                    @csrf
                    <button type="submit" class="button">Confirm Safe Mode</button>
                </form>
            </div>
        @endif

        <form method="POST" action="{{ route('routers.apply', $routerNode) }}" class="stack" style="margin-top: 1rem; max-width: 280px;" id="apply-form">
            @csrf
            <input type="hidden" name="expected_lock_version" value="{{ $routerNode->configuration_lock_version }}">
            <label for="safe_mode_minutes_{{ $routerNode->id }}" class="muted">Safe Mode minutes</label>
            <input id="safe_mode_minutes_{{ $routerNode->id }}" type="number" name="safe_mode_minutes" min="1" max="30" value="5">
            <button type="submit" class="button" id="apply-button">Apply current revision</button>
        </form>
    </div>

    <div class="workspace {{ $isStale ? 'stale' : '' }}" id="wincontrol-workspace" data-router-id="{{ $routerNode->id }}">
        <div class="loading-overlay" id="workspace-loading"><span class="badge">Loading…</span></div>

        @php
            $windows = [
                ['id' => 'interfaces', 'title' => 'Interfaces', 'x' => 12, 'y' => 12, 'w' => 420, 'h' => 260],
                ['id' => 'addresses', 'title' => 'Addresses', 'x' => 450, 'y' => 12, 'w' => 420, 'h' => 260],
                ['id' => 'routes', 'title' => 'Routes', 'x' => 880, 'y' => 12, 'w' => 360, 'h' => 260],
                ['id' => 'firewall', 'title' => 'Firewall', 'x' => 12, 'y' => 290, 'w' => 420, 'h' => 280],
                ['id' => 'dhcp', 'title' => 'DHCP leases', 'x' => 450, 'y' => 290, 'w' => 360, 'h' => 280],
                ['id' => 'pppoe', 'title' => 'PPPoE sessions', 'x' => 830, 'y' => 290, 'w' => 410, 'h' => 280],
                ['id' => 'traffic', 'title' => 'Interface traffic', 'x' => 12, 'y' => 590, 'w' => 520, 'h' => 260],
                ['id' => 'audit', 'title' => 'Audit log', 'x' => 550, 'y' => 590, 'w' => 340, 'h' => 260],
                ['id' => 'revisions', 'title' => 'Revisions', 'x' => 910, 'y' => 590, 'w' => 330, 'h' => 260],
                ['id' => 'diff', 'title' => 'Desired vs applied', 'x' => 12, 'y' => 870, 'w' => 600, 'h' => 240],
                ['id' => 'config', 'title' => 'Desired / applied JSON', 'x' => 630, 'y' => 870, 'w' => 610, 'h' => 240],
            ];
        @endphp

        @foreach ($windows as $window)
            <section class="win" data-window-id="{{ $window['id'] }}" style="left: {{ $window['x'] }}px; top: {{ $window['y'] }}px; width: {{ $window['w'] }}px; height: {{ $window['h'] }}px;">
                <div class="win-title"><strong>{{ $window['title'] }}</strong><span class="muted">drag / resize</span></div>
                <div class="win-body">
                    @if ($window['id'] === 'interfaces')
                        @include('routers.partials.interfaces', ['observed' => $observed])
                    @elseif ($window['id'] === 'addresses')
                        @include('routers.partials.addresses', ['observed' => $observed])
                    @elseif ($window['id'] === 'routes')
                        @include('routers.partials.routes', ['observed' => $observed])
                    @elseif ($window['id'] === 'firewall')
                        @if (($observed['firewall'] ?? []) !== [])
                            <pre>{{ json_encode($observed['firewall'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @else
                            <p class="muted">No observed firewall ruleset available.</p>
                        @endif
                    @elseif ($window['id'] === 'dhcp')
                        @include('routers.partials.dhcp', ['observed' => $observed])
                    @elseif ($window['id'] === 'pppoe')
                        @include('routers.partials.pppoe', ['observed' => $observed])
                    @elseif ($window['id'] === 'traffic')
                        @include('routers.partials.traffic', ['observed' => $observed])
                    @elseif ($window['id'] === 'audit')
                        <table class="table">
                            <thead><tr><th>Time</th><th>Action</th><th>Status</th></tr></thead>
                            <tbody>
                                @forelse ($routerNode->auditLogs->sortByDesc('created_at')->take(20) as $auditLog)
                                    <tr>
                                        <td>{{ $auditLog->created_at }}</td>
                                        <td>{{ $auditLog->action }}</td>
                                        <td>{{ $auditLog->status }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="muted">No audit entries.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    @elseif ($window['id'] === 'revisions')
                        <table class="table">
                            <thead><tr><th>#</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($routerNode->revisions->sortByDesc('revision_number')->take(12) as $revision)
                                    <tr>
                                        <td>#{{ $revision->revision_number }}</td>
                                        <td>{{ $revision->apply_status }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('routers.revisions.rollback', [$routerNode, $revision]) }}">
                                                @csrf
                                                <button type="submit" class="button button-secondary">Rollback</button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @elseif ($window['id'] === 'diff')
                        @if ($routerState['diff']['desired_vs_applied'] !== [])
                            <pre>{{ json_encode($routerState['diff']['desired_vs_applied'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        @else
                            <p class="muted">No configuration drift is currently recorded.</p>
                        @endif
                    @elseif ($window['id'] === 'config')
                        <h3 class="muted">Desired</h3>
                        <pre>{{ json_encode($routerNode->desired_configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        <h3 class="muted">Applied</h3>
                        <pre>{{ json_encode($routerNode->applied_configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    @endif
                </div>
                <div class="win-resize" aria-hidden="true"></div>
            </section>
        @endforeach
    </div>

    <script>
    (() => {
        const workspace = document.getElementById('wincontrol-workspace');
        if (!workspace) return;
        const storageKey = `nur-wincontrol-layout-v1-${workspace.dataset.routerId}`;
        const defaults = {};
        workspace.querySelectorAll('.win').forEach((win) => {
            defaults[win.dataset.windowId] = {
                left: win.offsetLeft, top: win.offsetTop, width: win.offsetWidth, height: win.offsetHeight
            };
        });

        const applyLayout = (layout) => {
            Object.entries(layout || {}).forEach(([id, box]) => {
                const win = workspace.querySelector(`[data-window-id="${id}"]`);
                if (!win || !box) return;
                win.style.left = `${box.left}px`;
                win.style.top = `${box.top}px`;
                win.style.width = `${box.width}px`;
                win.style.height = `${box.height}px`;
            });
        };

        try { applyLayout(JSON.parse(localStorage.getItem(storageKey) || 'null')); } catch (_) {}

        const persist = () => {
            const layout = {};
            workspace.querySelectorAll('.win').forEach((win) => {
                layout[win.dataset.windowId] = {
                    left: win.offsetLeft, top: win.offsetTop, width: win.offsetWidth, height: win.offsetHeight
                };
            });
            localStorage.setItem(storageKey, JSON.stringify(layout));
        };

        document.getElementById('reset-layout')?.addEventListener('click', () => {
            localStorage.removeItem(storageKey);
            applyLayout(defaults);
        });

        let active = null;
        workspace.addEventListener('pointerdown', (event) => {
            const title = event.target.closest('.win-title');
            const resize = event.target.closest('.win-resize');
            const win = event.target.closest('.win');
            if (!win) return;
            win.style.zIndex = String(Date.now());
            if (title) {
                active = { type: 'move', win, ox: event.clientX - win.offsetLeft, oy: event.clientY - win.offsetTop };
            } else if (resize) {
                active = { type: 'resize', win, ox: event.clientX, oy: event.clientY, ow: win.offsetWidth, oh: win.offsetHeight };
            } else {
                return;
            }
            event.preventDefault();
        });
        window.addEventListener('pointermove', (event) => {
            if (!active) return;
            if (active.type === 'move') {
                active.win.style.left = `${Math.max(0, event.clientX - active.ox)}px`;
                active.win.style.top = `${Math.max(0, event.clientY - active.oy)}px`;
            } else {
                active.win.style.width = `${Math.max(280, active.ow + (event.clientX - active.ox))}px`;
                active.win.style.height = `${Math.max(160, active.oh + (event.clientY - active.oy))}px`;
            }
        });
        window.addEventListener('pointerup', () => {
            if (active) persist();
            active = null;
        });

        const countdown = document.getElementById('safe-mode-countdown');
        if (countdown?.dataset.deadline) {
            const deadline = Date.parse(countdown.dataset.deadline);
            const tick = () => {
                const remaining = Math.max(0, Math.floor((deadline - Date.now()) / 1000));
                const mm = String(Math.floor(remaining / 60)).padStart(2, '0');
                const ss = String(remaining % 60).padStart(2, '0');
                countdown.textContent = remaining === 0 ? 'deadline passed — appliance/local watchdog should roll back' : `confirm within ${mm}:${ss}`;
            };
            tick();
            setInterval(tick, 1000);
        }

        const loading = document.getElementById('workspace-loading');
        document.getElementById('apply-form')?.addEventListener('submit', () => {
            loading?.classList.add('is-active');
            document.getElementById('apply-button')?.setAttribute('disabled', 'disabled');
        });
        document.getElementById('refresh-observe')?.addEventListener('click', () => {
            loading?.classList.add('is-active');
        });
    })();
    </script>
@endsection
