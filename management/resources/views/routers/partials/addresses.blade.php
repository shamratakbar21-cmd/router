@if (($observed['addresses'] ?? []) !== [])
    <table class="table">
        <thead>
            <tr>
                <th>Interface</th>
                <th>Address</th>
                <th>Family</th>
                <th>Scope</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($observed['addresses'] as $address)
                <tr>
                    <td>{{ $address['interface'] ?? 'unknown' }}</td>
                    <td>{{ $address['cidr'] ?? 'n/a' }}</td>
                    <td>{{ $address['family'] ?? 'n/a' }}</td>
                    <td>{{ $address['scope'] ?? 'n/a' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <p class="muted">No observed addresses available.</p>
@endif
