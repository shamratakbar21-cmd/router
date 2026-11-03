@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'Supported Feature Matrix | NurRouterOS '.$release['version'],
    'description' => 'Verified, unverified, and deferred networking capabilities for NurRouterOS '.$release['version'].' on OpenWrt '.$release['openwrt_base'].' (x86/64) and external WinControl.',
])

@section('content')
    <section class="page-hero">
        <div class="container">
            <span class="eyebrow">Hardware &amp; Software · Supported Feature Matrix</span>
            <h1>Verified, unverified, missing, or deferred.</h1>
            <p class="lead">Statuses match lab evidence for {{ $release['version'] }}. Missing features are never marketed as supported.</p>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Area</th>
                            <th>Status</th>
                            <th>Verification Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($featureMatrix as $row)
                            <tr>
                                <td><strong>{{ $row['area'] }}</strong></td>
                                <td><span class="badge {{ str_contains($row['status'], 'verified') && ! str_contains($row['status'], 'unverified') ? 'badge-ok' : 'badge-warn' }}">{{ $row['status'] }}</span></td>
                                <td>{{ $row['notes'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="cta-row" style="margin-top:24px;">
                <a class="btn btn-primary" href="{{ route('downloads') }}">Download {{ $release['version'] }}</a>
                <a class="btn btn-line" href="{{ route('docs.show', 'gaps') }}">Detailed gap matrix documentation →</a>
            </div>
        </div>
    </section>
@endsection
