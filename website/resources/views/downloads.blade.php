@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'Downloads | NurRouterOS '.$release['version'],
    'description' => 'Download NurRouterOS installer and virtual machine images, browse source, and set up WinControl.',
])

@section('content')
<style>
    .dl-page { --accent:#176bd6; background:#fff; padding:40px 0 72px; }
    .dl-page .container { max-width:1240px; }
    .dl-crumb { color:var(--muted); font-size:13px; margin-bottom:18px; }
    .dl-title { font-size:48px; font-weight:500; margin-bottom:30px; }
    .dl-tabs { display:flex; gap:30px; border-bottom:1px solid #dce1e7; overflow-x:auto; margin-bottom:36px; scrollbar-width:thin; scrollbar-color:#8aa9cc #eef3f8; }
    .dl-tabs::-webkit-scrollbar { height:5px; }
    .dl-tabs::-webkit-scrollbar-track { background:#eef3f8; }
    .dl-tabs::-webkit-scrollbar-thumb { background:#8aa9cc; border-radius:999px; }
    .dl-tabs a { flex-shrink:0; color:#526071; padding:14px 0; border-bottom:3px solid transparent; font-weight:600; }
    .dl-tabs a[aria-current="true"] { color:var(--accent); border-color:var(--accent); }
    .dl-tabs a:last-child { margin-left:auto; }
    .dl-grid { display:grid; grid-template-columns:minmax(0,1fr) 270px; gap:40px; align-items:start; }
    .dl-heading { display:flex; justify-content:space-between; gap:16px; align-items:center; margin-bottom:16px; }
    .dl-heading h2 { font-size:30px; font-weight:500; }
    .dl-copy { color:var(--muted); margin:0 0 24px; max-width:650px; }
    .dl-meta { display:flex; flex-wrap:wrap; gap:10px 24px; background:#f4f6f8; padding:17px 20px; font-size:14px; margin-bottom:22px; }
    .dl-meta span { color:var(--muted); }
    .dl-controls { display:flex; gap:12px; margin-bottom:18px; align-items:end; }
    .dl-controls label { font-size:12px; font-weight:600; color:var(--muted); display:grid; gap:6px; flex:1; }
    .dl-controls input,.dl-controls select { width:100%; min-width:0; border:1px solid #ced6df; border-radius:4px; padding:11px 12px; background:#fff; font:inherit; font-size:14px; color:var(--ink); }
    .dl-controls label:last-child { flex:0 0 170px; }
    .dl-table { border:1px solid #dce1e7; border-radius:4px; overflow:hidden; }
    .dl-table table { min-width:0; table-layout:fixed; }
    .dl-table th { letter-spacing:0; text-transform:none; font-size:13px; font-weight:600; background:#eef2f6; }
    .dl-table th:last-child { width:155px; text-align:right; }
    .dl-table td { vertical-align:top; padding:18px 20px; }
    .dl-table td:last-child { text-align:right; }
    .dl-file { display:block; color:var(--ink); font-weight:600; margin-bottom:3px; }
    .dl-sub { font-size:12px; color:var(--muted); overflow-wrap:anywhere; }
    .dl-table details { margin-top:9px; font-size:12px; }
    .dl-table summary { cursor:pointer; color:var(--accent); }
    .dl-table code { display:block; margin-top:8px; overflow-wrap:anywhere; font-size:11px; text-align:left; }
    .dl-download { display:inline-flex; gap:10px; align-items:center; padding:7px 13px; border:1px solid #b7cdeb; border-radius:4px; font-size:13px; font-weight:600; }
    .dl-size { display:block; font-size:12px; color:var(--muted); margin-top:6px; }
    .dl-side { border-top:3px solid var(--accent); background:#f5f7f9; padding:24px; }
    .dl-side h3 { font-size:18px; font-weight:600; margin-bottom:10px; }
    .dl-side p { color:var(--muted); font-size:14px; margin-bottom:16px; }
    .dl-side a { display:block; padding:10px 0; border-top:1px solid #dce2e9; font-size:14px; }
    .dl-notice { margin-top:22px; border-left:3px solid #dba345; padding:13px 17px; background:#fffbf3; color:#77511e; font-size:13px; }
    .dl-panel { scroll-margin-top:150px; margin-bottom:36px; }
    .dl-panel[hidden],.dl-table tr[hidden] { display:none; }
    .dl-card { border:1px solid var(--line); padding:26px; margin-top:18px; border-radius:4px; }
    .dl-card h3 { font-size:20px; margin-bottom:10px; }
    .dl-card p { color:var(--muted); margin-bottom:18px; }
    .dl-empty { padding:24px; color:var(--muted); }
    .dl-page a:focus-visible,.dl-page summary:focus-visible { outline:3px solid #86b7fa; outline-offset:4px; }
    @media(max-width:850px) { .dl-grid { grid-template-columns:1fr; gap:16px; } .dl-tabs { flex-wrap:wrap; gap:0 24px; overflow:visible; padding-bottom:6px; } .dl-tabs a { padding:10px 0; } .dl-tabs a:last-child { margin-left:0; } }
    @media(max-width:520px) { .dl-title { font-size:36px; } .dl-tabs { gap:0 20px; } .dl-tabs a { font-size:14px; } .dl-controls { flex-direction:column; align-items:stretch; } .dl-controls label:last-child { flex:auto; } .dl-table td,.dl-table th { padding:14px 10px; } .dl-table th:last-child { width:114px; } .dl-heading { align-items:start; flex-direction:column; } }
</style>
<section class="dl-page">
    <div class="container">
        <nav class="dl-crumb" aria-label="Breadcrumb"><a href="{{ route('home') }}">Home</a> &nbsp; / &nbsp; Software &nbsp; / &nbsp; Downloads</nav>
        <h1 class="dl-title">{{ $locale === 'bn' ? 'ডাউনলোড' : 'Downloads' }}</h1>
        <nav class="dl-tabs" aria-label="Download categories">
            <a href="#routeros" data-download-tab aria-current="true">NurRouterOS</a>
            <a href="#wincontrol" data-download-tab>WinControl</a>
            <a href="#source" data-download-tab>Source code</a>
            <a href="#verification" data-download-tab>Checksums &amp; tools</a>
            <a href="{{ route('release') }}">Changelog ↗</a>
        </nav>
        <div class="dl-grid">
            <div>
                <section class="dl-panel" id="routeros" aria-labelledby="routeros-title">
                    <div class="dl-heading"><h2 id="routeros-title">NurRouterOS</h2><span class="badge badge-warn">Development candidate</span></div>
                    <p class="dl-copy">Choose an installer or a ready-to-use virtual disk for your next router lab. Built on OpenWrt, with external WinControl management.</p>
                    <div class="dl-meta"><div><span>Version</span> &nbsp; <strong>{{ $release['version'] }}</strong></div><div><span>Architecture</span> &nbsp; <strong>{{ $release['target'] }}</strong></div><div><span>Base</span> &nbsp; OpenWrt {{ $release['openwrt_base'] }}</div></div>
                    <div class="dl-controls" hidden id="download-filters">
                        <label>Find a download<input id="download-search" type="search" placeholder="Search image name, BIOS, UEFI…"></label>
                        <label>Image format<select id="download-format"><option value="">All formats</option><option value="Installer ISO">Installer ISO</option><option value="QCOW2 lab image">QCOW2</option><option value="RAW image (gzip)">RAW (gzip)</option></select></label>
                    </div>
                    <div class="dl-table"><table><thead><tr><th scope="col">Image / compatibility</th><th scope="col">{{ $release['version'] }}</th></tr></thead><tbody>
                        @foreach ($release['artifacts'] as $artifact)
                            <tr data-artifact data-format="{{ $artifact['kind'] }}">
                                <td><span class="dl-file">{{ $artifact['kind'] }}</span><div class="dl-sub">{{ $artifact['compat'] }}</div><div class="dl-sub">{{ $artifact['filename'] }}</div><details><summary>SHA-256 checksum</summary><code>{{ $artifact['sha256'] }}</code></details></td>
                                <td><a class="dl-download" href="{{ $artifact['href'] }}" aria-label="Download {{ $artifact['filename'] }}">Download <span aria-hidden="true">↓</span></a><span class="dl-size">{{ number_format($artifact['size_bytes'] / 1048576, 1) }} MiB</span></td>
                            </tr>
                        @endforeach
                    </tbody></table><p class="dl-empty" id="download-empty" hidden>No images match. Try another name or choose All formats.</p></div>
                    <p id="download-count" class="dl-sub" role="status" style="margin-top:10px">{{ count($release['artifacts']) }} images available</p>
                    <div class="dl-notice"><strong>For isolated evaluation.</strong> {{ $release['qualification'] }}. Not production-ready or bare-metal qualified. BIOS and UEFI ext4 passed disposable-VM installation; physical USB payload discovery and hardware qualification remain unresolved. Automatic firmware A/B rollback is unsupported.</div>
                    <div class="dl-notice"><strong>Licensing boundary.</strong> Factory test licensing trust is removed in dev.14. No commercial signing trust is provisioned; commercial activation is not qualified.</div><div class="dl-card" style="border-left:3px solid #dba345;">
                        <div class="card-top"><span class="badge badge-warn">Historical boot-test only</span><span class="badge badge-line">Not primary</span></div>
                        <h3>{{ $bootCandidate['version'] }} historical console-correction ISO</h3>
                        <p>{{ $bootCandidate['scope'] }} {{ $bootCandidate['warning'] }}</p>
                        <p class="mono" style="margin-bottom:12px; overflow-wrap:anywhere;">SHA-256: {{ $bootCandidate['sha256'] }}</p>
                        <a class="btn btn-primary" href="{{ $bootCandidate['href'] }}">Download boot-test ISO ↓</a>
                        <a class="btn btn-line" href="{{ route('docs.show', 'boot-console-dev10') }}">Read required handover</a>
                        <a class="more" href="{{ $bootCandidate['checksums_href'] }}">Checksums →</a>
                    </div>
                </section>
                <section class="dl-panel" id="wincontrol" aria-labelledby="wincontrol-title">
                    <div class="dl-heading"><h2 id="wincontrol-title">WinControl</h2><span class="badge badge-line">External management</span></div>
                    <p class="dl-copy">Manage your NurRouterOS appliance from a separate Laravel management host. WinControl is not bundled in the router images.</p>
                    <p><a href="/releases/0.5.0-dev.8/SHA256SUMS">Historical dev.8 checksums</a> · <a href="{{ route('docs.show', 'qualification-dev8') }}">Historical dev.8 qualification</a></p><div class="dl-card"><h3>Set up your management workspace</h3><p>Follow the deployment guide for host requirements, SSH enrollment, and configuration. The current source is candidate-10-desktop. A standalone native desktop installer is not published.</p><a class="btn btn-primary" href="{{ route('docs.show', 'deploy') }}">Deployment guide ↗</a> <a class="btn btn-line" href="{{ route('screenshots') }}">View screenshots</a></div>
                </section>
                <section class="dl-panel" id="source" aria-labelledby="source-title">
                    <div class="dl-heading"><h2 id="source-title">Source code</h2><span class="badge badge-line">Reviewed public archives</span></div><p>Current reviewed source is available in the verified project-owned GitHub repository, checksum-verified archives and an offline Git bundle.</p>
                    <p class="dl-copy">Explore the reviewed dev.14 appliance source and candidate-10 desktop management source.</p>
                    <div class="dl-card"><h3>NurRouterOS {{ $release['version'] }}</h3><p>Download the reviewed appliance build source or the external Laravel WinControl source snapshot. Public access does not grant a new blanket source license; third-party terms remain applicable. The management test harness generates temporary signing keys; runtime code matches the accepted candidate.</p><a class="btn btn-primary" href="{{ $release['source']['appliance_href'] }}">Appliance source ↓</a> <a class="btn btn-line" href="{{ $release['source']['management_href'] }}">WinControl source ↓</a> <a class="btn btn-line" href="/releases/0.5.0-dev.14/reviewed-project-source.bundle">Reviewed Git bundle ↓</a></div>
                    <div class="dl-notice">Source commit: <code>{{ $release['source_commit'] }}</code>. <a href="{{ route('docs.show', 'source') }}">Provenance, package source index and license terms</a>. A clean-checkout bit-identical rebuild remains NOT RUN.</div>
                </section>
                <section class="dl-panel" id="verification" aria-labelledby="verification-title">
                    <div class="dl-heading"><h2 id="verification-title">Checksums &amp; tools</h2></div>
                    <p class="dl-copy">Verify image integrity before installation. Compare your downloaded file's SHA-256 with the published checksums.</p>
                    <div class="dl-card"><h3>Release verification</h3><p>The checksum list covers the distributed images; the manifest records the release artifacts.</p><a class="btn btn-primary" href="{{ $release['checksums_href'] }}">Download SHA256SUMS ↓</a> <a class="btn btn-line" href="{{ $release['manifest_href'] }}">Release manifest</a></div>
                </section>
            </div>
            <aside class="dl-side" aria-label="Download help"><h3>Getting started</h3><p>New installation? Start with the ISO and follow the install and recovery guide.</p><a href="{{ $release['iso']['href'] }}">Download installer ISO ↓</a><a href="{{ route('docs.show', 'install') }}">Installation &amp; recovery ↗</a><a href="{{ route('release') }}">What’s new in {{ $release['version'] }} ↗</a><a href="{{ route('docs.show', 'gaps') }}">Known limitations ↗</a><a href="{{ route('docs.index') }}">Documentation ↗</a></aside>
        </div>
    </div>
</section>
<script>
(() => {
    const tabs = [...document.querySelectorAll('[data-download-tab]')];
    const panels = [...document.querySelectorAll('.dl-panel')];
    function selectPanel() {
        const selected = tabs.find(tab => tab.hash === location.hash) || tabs[0];
        tabs.forEach(tab => tab.setAttribute('aria-current', String(tab === selected)));
        panels.forEach(panel => panel.hidden = '#' + panel.id !== selected.hash);
    }
    window.addEventListener('hashchange', selectPanel);
    selectPanel();
    const search = document.getElementById('download-search');
    const format = document.getElementById('download-format');
    const rows = [...document.querySelectorAll('[data-artifact]')];
    document.getElementById('download-filters').hidden = false;
    function filterImages() {
        const query = search.value.trim().toLowerCase();
        let count = 0;
        rows.forEach(row => {
            row.hidden = !row.textContent.toLowerCase().includes(query) || (format.value !== '' && row.dataset.format !== format.value);
            if (!row.hidden) count++;
        });
        document.getElementById('download-empty').hidden = count !== 0;
        document.getElementById('download-count').textContent = count + ' of ' + rows.length + ' images shown';
    }
    search.addEventListener('input', filterImages);
    format.addEventListener('change', filterImages);
})();
</script>
@endsection
