@extends('layouts.app')

@section('content')
    <div class="card">
        <h1>Licensing administration</h1>
        <p class="muted">Activation key plaintext is shown once at issue time and never stored. Audit logs record only key prefixes.</p>
        <p><a class="button button-secondary" href="{{ route('dashboard') }}">Back to dashboard</a></p>
    </div>

    @if (session('issued_activation_key'))
        <div class="flash-warn" role="status">
            <strong>Copy this activation key now</strong>
            <pre>{{ session('issued_activation_key') }}</pre>
            <div class="muted">Prefix {{ session('issued_key_prefix') }} — it will not be shown again.</div>
        </div>
    @endif

    @if (session('offline_envelope'))
        <div class="card">
            <h2>Offline activation response</h2>
            <pre>{{ session('offline_envelope') }}</pre>
        </div>
    @endif

    <div class="grid">
        <div class="card">
            <h2>Issue activation key</h2>
            <form method="POST" action="{{ route('licensing.keys.issue') }}" class="stack">
                @csrf
                <label>Plan
                    <select name="plan_code">
                        @foreach ($plans as $plan)
                            <option value="{{ $plan->code }}">{{ $plan->name }} ({{ $plan->code }})</option>
                        @endforeach
                    </select>
                </label>
                <label>Customer label <input name="customer_label" maxlength="255"></label>
                <label>Max activations <input type="number" name="max_activations" min="1" value="1"></label>
                <button class="button" type="submit">Issue key</button>
            </form>
        </div>

        <div class="card">
            <h2>Offline activate</h2>
            <form method="POST" action="{{ route('licensing.offline.activate') }}" class="stack">
                @csrf
                <label>Activation key <input name="activation_key" autocomplete="off"></label>
                <label>Offline request JSON <textarea name="offline_request_json"></textarea></label>
                <button class="button" type="submit">Sign offline response</button>
            </form>
        </div>

        <div class="card">
            <h2>Device transfer</h2>
            <form method="POST" action="{{ route('licensing.transfer') }}" class="stack">
                @csrf
                <label>From activation id <input type="number" name="from_activation_id"></label>
                <label>To device id <input name="to_device_id"></label>
                <label>Activation key <input name="activation_key" autocomplete="off"></label>
                <button class="button" type="submit">Transfer</button>
            </form>
        </div>
    </div>

    <div class="card">
        <h2>Plans</h2>
        <table class="table">
            <thead><tr><th>Code</th><th>Name</th><th>Days</th><th>Entitlements</th></tr></thead>
            <tbody>
                @foreach ($plans as $plan)
                    <tr>
                        <td>{{ $plan->code }}</td>
                        <td>{{ $plan->name }}</td>
                        <td>{{ $plan->duration_days ?? 'unlimited' }}</td>
                        <td><pre>{{ json_encode($plan->entitlements, JSON_PRETTY_PRINT) }}</pre></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Activation keys (hashed)</h2>
        <table class="table">
            <thead><tr><th>Prefix</th><th>Plan</th><th>Status</th><th>Seats</th><th></th></tr></thead>
            <tbody>
                @foreach ($keys as $key)
                    <tr>
                        <td>{{ $key->key_prefix }}</td>
                        <td>{{ $key->plan->code ?? '' }}</td>
                        <td>{{ $key->status }}</td>
                        <td>{{ $key->max_activations }}</td>
                        <td>
                            @if ($key->status !== 'revoked')
                                <form method="POST" action="{{ route('licensing.keys.revoke', $key) }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="admin_revoke">
                                    <button class="button button-secondary" type="submit">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Activations</h2>
        <table class="table">
            <thead><tr><th>ID</th><th>Device</th><th>Plan</th><th>Status</th><th>Mode</th><th></th></tr></thead>
            <tbody>
                @foreach ($activations as $activation)
                    <tr>
                        <td>{{ $activation->id }}</td>
                        <td>{{ $activation->device_id }}</td>
                        <td>{{ $activation->license->plan->code ?? '' }}</td>
                        <td>{{ $activation->status }}</td>
                        <td>{{ $activation->activation_mode }}</td>
                        <td>
                            @if ($activation->status === 'active')
                                <form method="POST" action="{{ route('licensing.activations.revoke', $activation) }}">
                                    @csrf
                                    <input type="hidden" name="reason" value="admin_revoke">
                                    <button class="button button-secondary" type="submit">Revoke</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
