@extends('layouts.site', ['brand' => $brand, 'title' => 'About NurRouter | OpenWrt RouterOS & WinControl', 'description' => 'Learn about the NurRouterOS x86/64 routing appliance, the external WinControl management plane, and our evidence-led engineering methodology.'])

@section('content')
<section class="page-hero"><div class="container"><span class="eyebrow">About NurRouter</span><h1>Networking software built in the open.</h1><p class="lead">NurRouterOS is an OpenWrt-based software router appliance paired with the external WinControl management application. The project publishes what has been verified and keeps experimental capabilities clearly labelled.</p></div></section>
<section class="sec"><div class="container article-grid"><article class="article-body">
    <img src="{{ asset('media/images/hero-ccr-appliance.svg') }}" alt="NurRouterOS x86_64 routing appliance" style="width:100%; height:auto; border-radius:4px; margin-bottom:24px;" width="960" height="340">
    <h2>What we are building</h2><p>NurRouterOS brings routing, firewall, VLAN, WAN PPPoE client, shaping, configuration revisions and recovery workflows into an operator-focused appliance. WinControl provides inventory, observed state, controlled configuration and audit history from a separate management host.</p>
    <h2>Evidence before claims</h2><p>Release notes distinguish unmodified-media tests, runtime overlays, stubbed tests, virtual appliances and physical hardware. A feature is not presented as supported merely because a package or configuration field exists.</p>
    <h2>Current scope</h2><p>The published release remains a controlled-pilot candidate verified primarily in isolated QEMU/KVM environments. It is not a blanket production-readiness or hardware-compatibility claim.</p>
    <div class="cta-row"><a class="btn btn-primary" href="{{ route('features') }}">Review feature evidence</a><a class="btn btn-line" href="{{ route('docs.index') }}">Read documentation</a></div>
</article><aside class="side-note">
    <img src="{{ $screenshots[1]['src'] }}" alt="WinControl dashboard preview" style="width:100%; height:auto; border-radius:3px; border:1px solid var(--line); margin-bottom:14px;" width="1200" height="750">
    <h3>Project principles</h3><p>Reproducible builds, explicit limitations, conservative recovery paths and no unsupported capacity claims.</p>
</aside></div></section>
@endsection
