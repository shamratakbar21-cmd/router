@extends('layouts.site', [
    'brand' => $brand,
    'title' => $product['name'] . ' | ' . $brand['name'],
    'description' => $product['summary'],
])

@section('content')
    <section class="hero">
        <div class="container hero-cols">
            <div>
                <span class="eyebrow">{{ $product['eyebrow'] }}</span>
                <h1>{{ $product['headline'] }}</h1>
                <p class="lead">{{ $product['summary'] }}</p>

                <div class="cta-row">
                    <a class="btn btn-primary" href="{{ route('builds') }}">Download NurRouterOS</a>
                    <a class="btn btn-line" href="{{ route('downloads.winbox') }}">Get NurWinBox</a>
                </div>
            </div>

            <div>
                <div class="panel">
                    <div class="panel-head">
                        <span>{{ $product['name'] }}</span>
                        <span class="badge">Overview</span>
                    </div>
                    <div class="kv"><span>Position</span><strong>{{ $product['highlight'] }}</strong></div>
                    @foreach ($product['specs'] as $spec)
                        <div class="kv">
                            <span>{{ $spec['label'] }}</span>
                            <strong>{{ $spec['value'] }}</strong>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">Capabilities</span>
                <h2>Built for practical deployment planning.</h2>
            </div>

            <div class="cols cols-2">
                @foreach ($product['capabilities'] as $capability)
                    <article class="card">
                        <h3>{{ $capability }}</h3>
                        <p>
                            Pair {{ $product['name'] }} hardware with NurRouterOS to apply these roles through WinControl
                            revisions, with Safe Mode rollback if a change needs to be reverted.
                        </p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>

    <section class="sec alt">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">{{ $product['name'] }} catalog</span>
                <h2>Models and target platforms.</h2>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Ports / Target</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($product['models'] as $model)
                            <tr>
                                <td><strong>{{ $model['name'] }}</strong></td>
                                <td>{{ $model['role'] ?? $model['target'] }}</td>
                                <td>{{ $model['ports'] ?? 'Release architecture' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="sec-head">
                <span class="eyebrow">Release channels</span>
                <h2>Runs the current NurRouterOS build channels.</h2>
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
                        <ul class="list">
                            @foreach ($channel['items'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endsection
