@if (($observed['interfaces'] ?? []) !== [])
    <table class="table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>State</th>
                <th>Master</th>
                <th>MTU</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($observed['interfaces'] as $interface)
                <tr>
                    <td>{{ $interface['name'] ?? 'unknown' }}</td>
                    <td>{{ $interface['type'] ?? 'unknown' }}</td>
                    <td>{{ $interface['state'] ?? 'unknown' }}</td>
                    <td>{{ $interface['master'] ?? 'n/a' }}</td>
                    <td>{{ $interface['mtu'] ?? 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No observed interfaces available.</p>
@endif
