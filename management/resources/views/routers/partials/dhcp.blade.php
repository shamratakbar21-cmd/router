@if (($observed['dhcp_leases'] ?? []) !== [])
    <table class="table">
        <thead>
            <tr>
                <th>IP</th>
                <th>MAC</th>
                <th>Hostname</th>
                <th>Expiry</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($observed['dhcp_leases'] as $lease)
                <tr>
                    <td>{{ $lease['ip'] ?? 'n/a' }}</td>
                    <td>{{ $lease['mac'] ?? 'n/a' }}</td>
                    <td>{{ $lease['hostname'] ?? 'n/a' }}</td>
                    <td>{{ $lease['expiry'] ?? 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No DHCP leases observed.</p>
@endif
