<h3>WAN PPPoE client</h3>
@if (($observed['pppoe_sessions'] ?? []) !== [])
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Up</th>
                <th>Device</th>
                <th>Address</th>
                <th>Uptime</th>
                <th>DNS</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($observed['pppoe_sessions'] as $session)
                <tr>
                    <td>{{ $session['name'] ?? 'unknown' }}</td>
                    <td>{{ ! empty($session['up']) ? 'yes' : 'no' }}</td>
                    <td>{{ $session['l3_device'] ?? ($session['device'] ?? 'n/a') }}</td>
                    <td>
                        @foreach (($session['ipv4_address'] ?? []) as $address)
                            {{ $address['address'] ?? '' }}/{{ $address['mask'] ?? '' }}
                        @endforeach
                    </td>
                    <td>{{ $session['uptime'] ?? 'n/a' }}s</td>
                    <td>{{ implode(', ', $session['dns_server'] ?? []) ?: 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No observed WAN PPPoE client sessions.</p>
@endif

<h3>PPPoE access server / BRAS (experimental)</h3>
<a href="{{ route('routers.bras.edit', $routerNode) }}">Configure access server</a>
<pre>{{ json_encode($observed['pppoe_server'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
