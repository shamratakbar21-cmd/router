@extends('layouts.site', [
    'brand' => $brand,
    'title' => $doc['title'].' | NurRouterOS '.$release['version'].' Docs',
    'description' => 'Official '.$doc['title'].' guide for NurRouterOS '.$release['version'].' on OpenWrt '.$release['openwrt_base'].' ('.$release['target'].') with external WinControl management.',
])

@section('content')
    <section class="page-hero">
        <div class="container">
            <span class="eyebrow">Support · Documentation</span>
            <h1>{{ $doc['title'] }}</h1>
            <p class="lead">
                <a href="{{ route('docs.index', ['lang' => $locale]) }}">← All documentation</a>
                · <a href="{{ asset('media/docs/'.$doc['file']) }}">Raw Markdown ({{ $doc['file'] }})</a>
            </p>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <article class="prose">
                {!! preg_replace('/<h1(\b[^>]*)>(.*?)<\/h1>/is', '<h2$1>$2</h2>', $html) !!}
            </article>
        </div>
    </section>
@endsection
