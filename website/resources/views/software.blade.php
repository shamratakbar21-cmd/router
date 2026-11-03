@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'Software | ' . $brand['name'],
    'description' => $software['summary'],
])

@section('content')
    <section class="hero">
        <div class="container">
            <span class="eyebrow">Software</span>
            <h1>{{ $software['headline'] }}</h1>
            <p class="lead">{{ $software['summary'] }}</p>

            <nav class="jumpnav">
                @foreach ($software['categories'] as $category)
                    <a href="#{{ $category['id'] }}">{{ $category['name'] }}</a>
                @endforeach
                <a href="{{ route('downloads.winbox') }}">WinBox</a>
            </nav>
        </div>
    </section>

    @foreach ($software['categories'] as $category)
        <section class="sec {{ $loop->even ? 'alt' : '' }}" id="{{ $category['id'] }}">
            <div class="container">
                <div class="sec-head">
                    <span class="eyebrow">{{ $category['tagline'] }}</span>
                    <h2>{{ $category['name'] }}</h2>
                    <p>{{ $category['description'] }}</p>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Package</th>
                                <th>Architecture</th>
                                <th>Includes</th>
                                <th>Size</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($category['downloads'] as $file)
                                <tr>
                                    <td><strong>{{ $file['name'] }}</strong></td>
                                    <td>{{ $file['arch'] }}</td>
                                    <td>{{ $file['package'] }}</td>
                                    <td>{{ $file['size'] }}</td>
                                    <td><a href="{{ $file['href'] }}">Download</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    @endforeach

    <section class="sec">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">Release channels</span>
                <h2>Choose a channel before you download.</h2>
                <p class="lead">Stable, long-term, and testing builds are described in full on the RouterOS builds page.</p>
            </div>

            <div class="cols cols-3">
                @foreach ($builds['channels'] as $channel)
                    <article class="card">
                        <div class="card-top">
                            <span class="badge">{{ $channel['badge'] }}</span>
                            <span class="badge badge-line">{{ $channel['name'] }}</span>
                        </div>
                        <h3>{{ $channel['name'] }} {{ $channel['version'] }}</h3>
                        <p>{{ $channel['description'] }}</p>
                    </article>
                @endforeach
            </div>

            <div class="cta-row">
                <a class="btn btn-primary" href="{{ route('builds') }}">Open RouterOS builds</a>
                <a class="btn btn-line" href="{{ route('downloads.winbox') }}">Get NurWinBox</a>
            </div>
        </div>
    </section>
@endsection
