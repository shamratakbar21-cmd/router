@if (($observed['routes'] ?? []) !== [])
    <table class="table">
        <thead>
            <tr>
                <th>Destination</th>
                <th>Gateway</th>
                <th>Device</th>
                <th>Protocol</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($observed['routes'] as $route)
                <tr>
                    <td>{{ $route['destination'] ?? 'default' }}</td>
                    <td>{{ $route['gateway'] ?? 'n/a' }}</td>
                    <td>{{ $route['device'] ?? 'n/a' }}</td>
                    <td>{{ $route['protocol'] ?? 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No observed routes available.</p>
@endif
