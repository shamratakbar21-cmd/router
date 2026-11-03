@extends('layouts.app')
@section('content')
    <h1>PPPoE server / BRAS — experimental milestone</h1>
    <p>WAN PPPoE client and subscriber access server are separate services. Pilot readiness remains BLOCKED.</p>
    <p>Stage the server configuration here, then use the router revision apply and Safe Mode workflow. Existing passwords are preserved when omitted; they are never displayed.</p>
    <form method="POST" action="{{ route('routers.bras.update', $routerNode) }}">
        @csrf
        @method('PUT')
        <label for="configuration">Access interfaces, pools, profiles, subscribers and RADIUS configuration (JSON)</label>
        <textarea id="configuration" name="configuration" rows="24" cols="90" autocomplete="off" spellcheck="false">{{ json_encode($configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea>
        <button type="submit">Stage server configuration</button>
    </form>
    <h2>{{ __('wincontrol.bras_sessions') }}</h2>
    <form method="GET" action="{{ route('routers.bras.edit', $routerNode) }}">
        <label for="session-query">{{ __('wincontrol.bras_find') }}</label>
        <input id="session-query" type="search" name="q" maxlength="128" value="{{ $sessionQuery }}">
        <label for="session-sort">{{ __('wincontrol.bras_sort') }}</label>
        <select id="session-sort" name="sort">
            @foreach (['username' => 'Username', 'address' => 'Address', 'state' => 'State', 'uptime' => 'Uptime'] as $value => $label)
                <option value="{{ $value }}" @selected($sessionSort === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button type="submit">{{ __('wincontrol.bras_filter') }}</button>
    </form>
    <p>{{ __('wincontrol.bras_matching', ['count' => $sessions->total(), 'page' => $sessions->currentPage(), 'pages' => $sessions->lastPage()]) }}</p>
    <table><thead><tr><th>{{ __('wincontrol.bras_interface') }}</th><th>{{ __('wincontrol.bras_username') }}</th><th>{{ __('wincontrol.bras_calling') }}</th><th>{{ __('wincontrol.bras_address') }}</th><th>{{ __('wincontrol.bras_rate') }}</th><th>{{ __('wincontrol.bras_state') }}</th><th>{{ __('wincontrol.bras_uptime') }}</th></tr></thead><tbody>
        @forelse ($sessions as $session)
            <tr><td>{{ $session['interface'] }}</td><td>{{ $session['username'] }}</td><td>{{ $session['calling_station'] }}</td><td>{{ $session['address'] }}</td><td>{{ $session['rate_limit'] }}</td><td>{{ $session['state'] }}</td><td>{{ $session['uptime'] }}</td></tr>
        @empty
            <tr><td colspan="7">{{ __('wincontrol.bras_empty') }}</td></tr>
        @endforelse
    </tbody></table>
    {{ $sessions->links() }}
    <h2>{{ __('wincontrol.bras_accounting') }}</h2>
    <pre>{{ json_encode($observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
@endsection
