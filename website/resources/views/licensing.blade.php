@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'Licensing | NurRouterOS '.$release['version'],
    'description' => 'Device-bound Ed25519 licensing plans and enforcement boundaries for NurRouterOS.',
])

@section('content')
    <section class="page-hero">
        <div class="container">
            <span class="eyebrow">Software · Licensing Control Plane</span>
            <h1>Ed25519 device-bound licensing policies.</h1>
            <p class="lead">Signed license payloads are verified on the appliance with a public key. Expired or unverified states block entitlement-gated configuration changes without disrupting active packet forwarding. End-to-end commercial activation is not qualified for this candidate.</p>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Duration</th>
                            <th>Max routers</th>
                            <th>Max PPPoE sessions</th>
                            <th>Features</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($licensingPlans as $plan)
                            <tr>
                                <td><strong>{{ $plan['name'] }}</strong><div class="mono">{{ $plan['code'] }}</div></td>
                                <td>{{ $plan['duration'] }}</td>
                                <td>{{ $plan['max_routers'] }}</td>
                                <td>{{ $plan['max_pppoe_sessions'] }}</td>
                                <td>{{ $plan['features'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="muted" style="margin-top:14px;">Plan values are implemented policy ceilings, not demonstrated subscriber-capacity or commercial-availability claims. Historical dev.8 capacity evidence does not qualify dev.14. dev.14 provisions no commercial signing trust; commercial activation is not qualified.</p>

            <div class="sec-head" style="margin-top:44px; margin-bottom:0;">
                <span class="eyebrow">Enforcement Model</span>
                <h2>Activation &amp; operational continuity</h2>
                <ul class="list">
                    <li>The implemented workflow supports online or offline activation against a device_id from first-boot identity; current appliance verification used lab signing, not qualified commercial activation.</li>
                    <li>Expired/revoked/unverified states block entitlement-gated mutations; forwarding and local recovery continue.</li>
                    <li>License signing private keys stay on the management host only; images embed public keys.</li>
                    <li>Licensing administration is authenticated and is not exposed on this public website.</li>
                </ul>
                <div class="cta-row">
                    <a class="btn btn-line" href="{{ route('docs.show', 'licensing') }}">Full licensing documentation →</a>
                </div>
            </div>
        </div>
    </section>
@endsection
