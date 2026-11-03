@extends('layouts.site', ['brand' => $brand, 'title' => 'Privacy Notice & Data Posture | NurRouter', 'description' => 'Privacy notice explaining server access logs, cookie posture, and data handling for visitors to the NurRouter public website and download portal.'])

@section('content')
<section class="page-hero"><div class="container"><span class="eyebrow">Legal</span><h1>Privacy notice.</h1><p class="lead">Effective 3 November 2026. This notice explains the limited information involved when you visit this public website.</p></div></section>
<section class="sec"><div class="container article-grid"><article class="article-body">
    <h2>Information processed</h2><p>The web server and hosting infrastructure may process standard request information such as IP address, timestamp, requested path, response status, user agent and security-event details. This website does not provide public user registration, a marketing subscription form or an online purchase cart.</p>
    <h2>Cookies and local storage</h2><p>The public informational pages do not intentionally use advertising, profiling or analytics cookies. Essential infrastructure cookies may be used if hosting or security configuration requires them.</p>
    <h2>Downloads and external links</h2><p>Download requests may appear in server access logs. Links to GitHub and other external services are governed by those services’ privacy practices. Do not submit secrets or subscriber data through public issue trackers.</p>
    <h2>Retention and security</h2><p>Operational logs should be retained only as needed for availability, abuse prevention, troubleshooting and legal obligations. Reasonable technical safeguards reduce risk, but no internet service can guarantee absolute security or survival of permanent storage failure.</p>
    <h2>Your choices</h2><p>You may avoid external services by not following their links. For a privacy concern related to this site, use the project contact route and avoid including unnecessary personal or confidential information.</p>
    <h2>Changes</h2><p>This notice may change when the site adds new data-processing features or contact channels. Material additions should be reflected here before deployment.</p>
</article><aside class="side-note"><h3>Current data posture</h3><p>No public accounts, newsletter, behavioral advertising or payment processing are implemented on this website.</p></aside></div></section>
@endsection
