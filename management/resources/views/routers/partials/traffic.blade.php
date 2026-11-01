@if (($observed['traffic'] ?? []) !== [])
    <table class="table">
        <thead>
            <tr>
                <th>Interface</th>
                <th>State</th>
                <th>RX bytes</th>
                <th>TX bytes</th>
                <th>RX packets</th>
                <th>TX packets</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($observed['traffic'] as $row)
                <tr>
                    <td>{{ $row['name'] ?? 'unknown' }}</td>
                    <td>{{ $row['operstate'] ?? 'n/a' }}</td>
                    <td>{{ $row['rx_bytes'] ?? 'n/a' }}</td>
                    <td>{{ $row['tx_bytes'] ?? 'n/a' }}</td>
                    <td>{{ $row['rx_packets'] ?? 'n/a' }}</td>
                    <td>{{ $row['tx_packets'] ?? 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No interface traffic counters observed.</p>
@endif
