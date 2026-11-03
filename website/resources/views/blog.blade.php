@extends('layouts.site', ['brand' => $brand, 'title' => 'Engineering Blog & Release Bulletins | NurRouter', 'description' => 'Read engineering build notes, release bulletins, recovery rehearsal guides, and external WinControl management architecture updates from NurRouter.'])

@section('content')
<section class="page-hero"><div class="container"><span class="eyebrow">NurRouter blog</span><h1>Build notes and field guidance.</h1><p class="lead">Release context, appliance engineering decisions and practical guidance for operators evaluating NurRouterOS.</p></div></section>
<section class="sec"><div class="container"><div class="cols cols-3">
    <article class="card news-card">
        <div class="mtk-card-visual"><img src="{{ asset('media/images/news-rc4-release.svg') }}" alt="Controlled pilot release engineering" loading="lazy" width="640" height="300"></div>
        <p class="post-meta">Release engineering · November 2026</p><h3>What “controlled pilot” means</h3><p>How candidate identity, fresh-media testing, recovery evidence and known limitations shape a responsible pilot decision.</p><a class="more" href="{{ route('release') }}">Read the release record →</a>
    </article>
    <article class="card news-card">
        <div class="mtk-card-visual"><img src="{{ asset('media/images/news-recovery-guide.svg') }}" alt="Plan recovery before installation" loading="lazy" width="640" height="300"></div>
        <p class="post-meta">Operations · November 2026</p><h3>Plan recovery before installation</h3><p>Choose console access, preserve configuration backups and rehearse the manual restore path before relying on a software router.</p><a class="more" href="{{ route('docs.show', 'install') }}">Open the recovery guide →</a>
    </article>
    <article class="card news-card">
        <div class="mtk-card-visual"><img src="{{ $screenshots[1]['src'] }}" alt="Why WinControl stays external" loading="lazy" width="1200" height="750"></div>
        <p class="post-meta">Management · November 2026</p><h3>Why WinControl stays external</h3><p>Separating the management plane from forwarding keeps operator workflows, audit history and recovery responsibilities explicit.</p><a class="more" href="{{ route('screenshots') }}">Explore WinControl →</a>
    </article>
</div><div class="warn-box" style="margin-top:28px">This blog currently links to versioned engineering records rather than maintaining a separate publishing database. Dates and support statements follow the associated release evidence.</div></div></section>
@endsection
