@extends('layouts.site', [
    'brand' => $brand,
    'title' => 'NUR WinControl Operator Screenshots | NurRouterOS',
    'description' => 'Explore operator workspace screenshots from the external Laravel WinControl management plane: router inventory, live telemetry, and staged revisions.',
])

@section('content')
    <section class="page-hero">
        <div class="container">
            <span class="eyebrow">Software · NUR WinControl</span>
            <h1>Operator workspace screenshots</h1>
            <p class="lead">Captured from the external Laravel WinControl management application. WinControl runs outside the router appliance over pinned SSH. These are original NUR candidate-10 captures from the accepted isolated dev.13 lab; dev.14 factory cleanup does not change the desktop at 2048×1088 and 100% zoom. They contain no customer data and do not claim pixel-identical RouterOS behavior.</p>
        </div>
    </section>

    <section class="sec">
        <div class="container">
            <div class="cols cols-2">
                @foreach ($screenshots as $shot)
                    <figure class="shot">
                        <a href="{{ $shot['src'] }}" aria-label="Open full-resolution {{ $shot['alt'] }}"><img src="{{ $shot['src'] }}" alt="{{ $shot['alt'] }}" loading="lazy" width="2048" height="1088"></a>
                        <figcaption><strong>{{ $shot['alt'] }}</strong> — {{ $shot['caption'] }}</figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    </section>
@endsection
