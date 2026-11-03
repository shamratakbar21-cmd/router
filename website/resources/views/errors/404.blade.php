@extends('layouts.site', [
    'brand' => config('nurrouter.brand'),
    'release' => config('nurrouter.release'),
    'i18n' => config('nurrouter.i18n.en'),
    'locale' => 'en',
    'title' => 'Page not found | NurRouter',
    'description' => 'The page you requested could not be found on nurrouter.com.',
    'robots' => 'noindex, nofollow',
])

@section('content')
    <section class="hero">
        <div class="container">
            <span class="eyebrow">Error 404</span>
            <h1>Page not found</h1>
            <p class="lead">The page you requested does not exist or has moved.</p>
            <div class="cta-row">
                <a class="btn btn-primary" href="{{ route('home') }}">Back to home</a>
                <a class="btn btn-line" href="{{ route('downloads') }}">Downloads</a>
                <a class="btn btn-line" href="{{ route('docs.index') }}">Documentation</a>
            </div>
        </div>
    </section>
@endsection
