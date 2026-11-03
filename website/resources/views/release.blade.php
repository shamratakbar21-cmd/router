@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'Release Notes & Changelogs | NurRouterOS '.$release['version'],
    'description' => 'Release notes, SHA-256 artifact manifests, installer ISO details, and qualification scope for NurRouterOS '.$release['version'].' (OpenWrt '.$release['openwrt_base'].').',
])

@section('content')
    <section class="page-hero">
        <div class="container">
            <span class="eyebrow">Software · Changelogs &amp; Release Notes</span>
            <h1>NurRouterOS {{ $release['version'] }}</h1>
            <p class="lead">Development candidate with scoped BIOS/UEFI ext4 VM installation and ARP/recovery verification. OpenWrt base {{ $release['openwrt_base'] }}, target {{ $release['target'] }}.</p>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="warn-box" style="margin-bottom:28px">
                <strong style="display:block; margin-bottom:8px;">Qualification &amp; Scope Boundaries</strong>
                <ul class="list" style="margin-top:6px;">
                    @foreach ($limitations as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            </div>

            <div class="cols cols-2">
                <article class="card">
                    <div class="card-top">
                        <span class="badge badge-ok">Primary Artifact</span>
                        <span class="badge badge-line">{{ $release['target'] }}</span>
                    </div>
                    <h3>Installer ISO</h3>
                    <p class="mono" style="margin-bottom:6px;">{{ $release['iso']['filename'] }}</p>
                    <p style="margin-bottom:8px;">Size: {{ number_format($release['iso']['size_bytes'] / 1048576, 1) }} MiB</p>
                    <p class="mono">SHA-256: {{ $release['iso']['sha256'] }}</p>
                    <a class="more" href="{{ $release['iso']['href'] }}">Download ISO →</a>
                </article>
                <article class="card">
                    <div class="card-top">
                        <span class="badge">Source &amp; Support</span>
                    </div>
                    <h3>Support / source</h3>
                    <p style="margin-bottom:10px;">{{ $support['note'] }}</p>
                    <p>{{ $support['source_status'] }}</p>
                    <a class="more" href="{{ $release['source']['appliance_href'] }}">Appliance source archive →</a>
                    <a class="more" style="padding-top:8px;" href="{{ $release['source']['management_href'] }}">WinControl source archive →</a>
                    <a class="more" style="padding-top:8px;" href="{{ route('docs.show', 'release') }}">Detailed release notes →</a>
                </article>
            </div>

            <div class="warn-box" style="margin-top:28px;">
                <div class="card-top"><span class="badge badge-warn">Historical hardware boot-test candidate</span><span class="badge badge-line">Not the primary release</span></div>
                <h2 style="margin:10px 0;">NurRouterOS {{ $bootCandidate['version'] }}</h2>
                <p>{{ $bootCandidate['scope'] }}</p>
                <p style="margin-top:8px;"><strong>{{ $bootCandidate['warning'] }}</strong></p>
                <p class="mono" style="margin-top:12px; overflow-wrap:anywhere;">{{ $bootCandidate['filename'] }}<br>SHA-256: {{ $bootCandidate['sha256'] }}</p>
                <div class="cta-row">
                    <a class="btn btn-primary" href="{{ $bootCandidate['href'] }}">Download boot-test ISO</a>
                    <a class="btn btn-line" href="{{ route('docs.show', 'boot-console-dev10') }}">Boot-console handover</a>
                    <a class="btn btn-line" href="{{ $bootCandidate['manifest_href'] }}">Manifest</a>
                </div>
            </div>
        </div>
    </section>
@endsection
