@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'Operator Documentation & Guides | NurRouterOS '.$release['version'],
    'description' => 'Official installation, recovery, external WinControl deployment, Ed25519 licensing, and release documentation for NurRouterOS '.$release['version'].'.',
])

@section('content')
    <section class="page-hero">
        <div class="container">
            <span class="eyebrow">Support · Documentation</span>
            <h1>Operator guides for {{ $release['version'] }}</h1>
            <p class="lead">Installation, recovery rehearsal, external WinControl deployment, licensing verification, and feature matrices.</p>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="cols cols-2">
                @foreach ($docs as $doc)
                    <article class="card">
                        <div class="card-top">
                            <span class="badge">Manual</span>
                            <span class="mono" style="color:var(--muted);">{{ $doc['file'] }}</span>
                        </div>
                        <h3>{{ $doc['title'] }}</h3>
                        <p>Official technical reference for NurRouterOS {{ $release['version'] }} ({{ $release['target'] }}).</p>
                        <a class="more" href="{{ route('docs.show', ['slug' => $doc['slug'], 'lang' => $locale]) }}">Open guide →</a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endsection
