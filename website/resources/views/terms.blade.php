@extends('layouts.site', ['brand' => $brand, 'title' => 'Terms of Use & Pilot Scope | NurRouter', 'description' => 'Terms governing use of the NurRouter website, NurRouterOS release-candidate software downloads, Ed25519 licensing, and documentation materials.'])

@section('content')
<section class="page-hero"><div class="container"><span class="eyebrow">Legal</span><h1>Terms of use.</h1><p class="lead">Effective 3 November 2026. These terms apply to this website and the release-candidate materials made available through it.</p></div></section>
<section class="sec"><div class="container article-grid"><article class="article-body">
    <h2>Release-candidate software</h2><p>NurRouterOS artifacts are provided for evaluation and controlled-pilot use within their documented qualification scope. They are not represented as universally production-ready, suitable for life-safety systems, or qualified for unspecified hardware and workloads.</p>
    <h2>Your responsibility</h2><p>You are responsible for validating compatibility, maintaining console and recovery access, protecting credentials, reviewing configuration changes, preserving backups and complying with laws applicable to your network and subscribers.</p>
    <h2>Licensing and third-party components</h2><p>Use of each software component remains subject to its applicable license. Website access or an appliance download does not grant rights beyond those licenses. Device-bound commercial licensing behavior, where present, is described separately and must not be bypassed.</p>
    <h2>No warranty</h2><p>To the maximum extent permitted by applicable law, evaluation materials are provided “as is” without warranties of uninterrupted operation, fitness for a particular purpose or non-infringement. Published evidence describes specific tests, not a guarantee of future performance.</p>
    <h2>Prohibited use</h2><p>Do not use the site or software to gain unauthorized access, disrupt networks, evade license controls, distribute malware, expose subscriber data, or misrepresent experimental capabilities as verified.</p>
    <h2>Changes</h2><p>These terms may be updated as the project and release process evolve. The effective date above identifies this version.</p>
</article><aside class="side-note"><h3>Important</h3><p>This page is a project operating notice, not a substitute for legal advice or a separately executed commercial agreement.</p></aside></div></section>
@endsection
