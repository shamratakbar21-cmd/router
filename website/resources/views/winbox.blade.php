@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'NurWinBox Downloads | ' . $brand['name'],
    'description' => 'WinBox-style download page for NurRouterOS desktop management clients.',
])

@section('content')
    <div class="crumbbar">
        <div class="container">
            <div class="crumbs">
                <a href="{{ route('home') }}">Home</a>
                <span>/</span>
                <span>Software</span>
                <span>/</span>
                <strong>Downloads</strong>
            </div>

            <nav class="seg">
                @foreach ($downloadNavigation as $item)
                    <a href="{{ $item['href'] }}" class="{{ ! empty($item['active']) ? 'is-active' : '' }}">{{ $item['label'] }}</a>
                @endforeach
            </nav>
        </div>
    </div>

    <section class="band" id="webfig">
        <div class="container band-cols">
            <div>
                <span class="eyebrow">{{ $winbox['name'] }}</span>
                <h1>{{ $winbox['headline'] }}</h1>
                <p class="lead">{{ $winbox['summary'] }}</p>

                <div class="pills">
                    <span class="lbl">Platforms</span>
                    @foreach ($winbox['webfig'] as $item)
                        <a class="pill ghost" href="{{ $item['href'] }}">{{ $item['label'] }}</a>
                    @endforeach
                    @foreach ($winbox['desktop_downloads'] as $item)
                        <a class="pill" href="{{ $item['href'] }}">{{ $item['platform'] }} {{ $item['variant'] }}</a>
                    @endforeach
                    @foreach ($winbox['mobile_downloads'] as $item)
                        <a class="pill ghost" href="{{ $item['href'] }}">{{ $item['platform'] }}</a>
                    @endforeach
                </div>
            </div>

            <div class="panel">
                <div class="panel-head">
                    <span>{{ $winbox['name'] }} client</span>
                    <span class="badge badge-ok">{{ $winbox['version'] }}</span>
                </div>
                <div class="kv"><span>Version</span><strong>{{ $winbox['version'] }}</strong></div>
                <div class="kv"><span>Released</span><strong>{{ $winbox['released_at'] }}</strong></div>
                <div class="kv"><span>Desktop clients</span><strong>Linux · Windows · macOS</strong></div>
                <div class="kv"><span>Mobile clients</span><strong>Android · iOS (planned)</strong></div>
            </div>
        </div>
    </section>

    <section class="sec" id="tools">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">Desktop clients</span>
                <h2>Download {{ $winbox['name'] }} for your operating system.</h2>
                <p class="lead">{{ $winbox['download_notes'][0] }}</p>
            </div>

            <div class="cols cols-3">
                @foreach ($winbox['desktop_downloads'] as $item)
                    <article class="card">
                        <div class="card-top">
                            <span class="badge">{{ $item['status'] }}</span>
                        </div>
                        <h3>{{ $item['platform'] }}</h3>
                        <p>{{ $item['variant'] }} build</p>
                        <a class="more" href="{{ $item['href'] }}">Download {{ $item['platform'] }}</a>
                    </article>
                @endforeach
            </div>

            <ul class="list" style="margin-top: 26px;">
                @foreach ($winbox['download_notes'] as $note)
                    <li>{{ $note }}</li>
                @endforeach
            </ul>
        </div>
    </section>

    <section class="sec alt" id="mobile">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">Mobile</span>
                <h2>Mobile clients for on-the-go router management.</h2>
            </div>

            <div class="cols cols-3">
                @foreach ($winbox['mobile_downloads'] as $item)
                    <article class="card">
                        <div class="card-top">
                            <span class="badge badge-warn">{{ $item['status'] }}</span>
                        </div>
                        <h3>{{ $item['platform'] }}</h3>
                        <p>Native app planned for the NurRouterOS management API.</p>
                        <a class="more" href="{{ $item['href'] }}">Notify me</a>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">Inside the client</span>
                <h2>Operator-focused panels across the whole platform.</h2>
            </div>

            <div class="cols cols-4">
                @foreach ($winbox['feature_panels'] as $panel)
                    <article class="card">
                        <h3>{{ $panel['title'] }}</h3>
                        <p>{{ $panel['body'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="sec alt" id="changelog">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">Changelog</span>
                <h2>What’s new</h2>
            </div>

            <div class="log">
                @foreach ($winbox['changelog'] as $entry)
                    <p>{{ $entry }}</p>
                @endforeach
            </div>
        </div>
    </section>
@endsection
