@extends('layouts.app')

@section('content')
    @php
        $state = $status['state'] ?? 'unknown';
        $okStates = ['valid', 'unlicensed', 'offline_within_grace'];
        $warnStates = ['expired_within_grace', 'offline_within_grace'];
        $badge = in_array($state, $okStates, true) ? 'badge-ok' : (in_array($state, $warnStates, true) ? 'badge-warn' : 'badge-error');
    @endphp

    <div class="card">
        <h1>License — {{ $routerNode->name }}</h1>
        <p class="muted">{{ $routerNode->namespace_name }} | device {{ $routerNode->device_id ?? 'unassigned' }}</p>
        <p>
            <a class="button button-secondary" href="{{ route('routers.show', $routerNode) }}">Back to router</a>
            <a class="button button-secondary" href="{{ route('licensing.offline.request', $routerNode) }}">Offline request</a>
            <a class="button button-secondary" href="{{ route('licensing.admin') }}">Licensing admin</a>
        </p>
    </div>

    @if (! empty($error) || session('license_error'))
        <div class="flash-error" role="alert">
            <strong>License error</strong>
            <div>{{ $error ?: session('license_error') }}</div>
            <div class="muted" style="margin-top: 0.5rem;">
                Existing PPPoE/subscriber sessions and firewall rules are not torn down by this error.
                Local recovery, Safe Mode, and on-box administration remain available.
            </div>
        </div>
    @endif

    @if (! empty($refreshedEnvelope))
        <div class="card">
            <h2>Signed status refresh envelope</h2>
            <p class="muted">Install on the appliance as <code>/etc/nurrouter/license.json</code> and write the current epoch to <code>/etc/nurrouter/license-last-refresh</code>. Do not use unsigned revoke flags.</p>
            <pre>{{ $refreshedEnvelope }}</pre>
        </div>
    @endif

    <div class="card">
        <h2>Status</h2>
        <p>
            State:
            <span class="badge {{ $badge }}">{{ $state }}</span>
        </p>
        <p>{{ $status['message'] ?? '' }}</p>
        <ul class="muted">
            <li>Allow entitlement-gated mutations: {{ ! empty($status['allow_entitlement_mutations']) ? 'yes' : 'no' }}</li>
            <li>Expires: {{ $status['expires_at'] ?? 'n/a' }}</li>
            <li>Grace / offline deadline: {{ $status['grace_expires_at'] ?? 'n/a' }}</li>
            <li>Max offline revocation delay (days): {{ $status['max_offline_revocation_delay_days'] ?? config('licensing.max_offline_revocation_delay_days') }}</li>
            <li>Disconnect subscribers: {{ ! empty($status['disconnect_subscribers']) ? 'yes' : 'no' }}</li>
            <li>Block local recovery: {{ ! empty($status['block_local_recovery']) ? 'yes' : 'no' }}</li>
            <li>Block Safe Mode: {{ ! empty($status['block_safe_mode']) ? 'yes' : 'no' }}</li>
        </ul>
        @if (! empty($status['entitlements']))
            <pre>{{ json_encode($status['entitlements'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        @endif
        @if ($activation)
            <form method="POST" action="{{ route('licensing.refresh', $routerNode) }}" style="margin-top: 1rem;">
                @csrf
                <button class="button button-secondary" type="submit">Authenticated status refresh</button>
            </form>
        @endif
    </div>

    <div class="card">
        <h2>Activate / renew online</h2>
        <form method="POST" action="{{ route('licensing.activate', $routerNode) }}" class="stack" style="max-width: 480px;">
            @csrf
            <label>Activation key
                <input name="activation_key" autocomplete="off" required>
            </label>
            <label>Device id (optional inventory override — not a ownership proof hash)
                <input name="device_id" value="{{ old('device_id', $routerNode->device_id) }}">
            </label>
            <button class="button" type="submit">Activate</button>
        </form>
    </div>

    @if ($activation)
        <div class="card">
            <h2>Current activation</h2>
            <p class="muted">Mode {{ $activation->activation_mode }} | Activated {{ $activation->activated_at }} | Last refresh {{ $activation->last_status_refresh_at }}</p>
            <pre>{{ json_encode(json_decode($activation->signed_payload, true)['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
@endsection
