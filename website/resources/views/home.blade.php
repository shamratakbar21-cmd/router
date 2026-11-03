@extends('layouts.site', ['brand' => $brand, 'title' => 'NurRouterOS '.$release['version'].' and NUR WinControl', 'description' => $brand['description']])

@section('content')
<section class="mtk-hero"><div class="container mtk-hero-main">
    <div>
        <span class="mtk-hero-kicker">NurRouterOS {{ $release['version'] }} · OpenWrt {{ $release['openwrt_base'] }} · {{ $release['target'] }}</span>
        <h1>{{ $locale === 'bn' ? 'নেটওয়ার্ক পরিচালনায় দৃশ্যমান নিয়ন্ত্রণ।' : 'Routing and network operations built for control.' }}</h1>
        <p class="mtk-hero-promise">{{ $locale === 'bn' ? 'এক ডেস্কটপে কনফিগারেশন, পর্যবেক্ষণ ও পুনরুদ্ধার' : 'Configuration, observation and recovery in one workspace' }}</p>
        <p class="mtk-hero-lead">{{ $locale === 'bn' ? 'বহিরাগত WinControl-এ বাস্তব IPv4 ARP তথ্য, পরিচালিত স্ট্যাটিক এন্ট্রি, ভাসমান উইন্ডো এবং সংরক্ষিত ওয়ার্কস্পেস। প্রয়োগের পর অ্যাপ্লায়েন্সের বাস্তব অবস্থা যাচাই করা হয়।' : 'An OpenWrt-based appliance with external WinControl management: real IPv4 neighbor telemetry, managed static ARP, floating windows and saved workspaces. Applied changes are checked against actual appliance state.' }}</p>
        <div class="mtk-hero-actions"><a class="mtk-button mtk-button-primary" href="{{ route('downloads', ['lang' => $locale]) }}">{{ $i18n['cta_download'] }}</a><a class="mtk-button mtk-button-ghost-light" href="{{ route('screenshots', ['lang' => $locale]) }}">{{ $locale === 'bn' ? 'বাস্তব স্ক্রিনশট' : 'Real screenshots' }}</a><a class="mtk-button mtk-button-ghost-light" href="https://admin.nurrouter.com/login">WinControl login</a></div>
    </div>
    <figure class="shot"><a href="{{ route('screenshots', ['lang' => $locale]) }}"><img src="{{ $screenshots[0]['src'] }}" alt="{{ $screenshots[0]['alt'] }}" width="2048" height="1088"></a><figcaption>{{ $locale === 'bn' ? 'বিচ্ছিন্ন dev.13 VM-এ candidate-10; 2048×1088, 100% জুম।' : 'Accepted candidate-10 desktop on unchanged dev.13: 2048×1088, 100% zoom. Isolated VM capture.' }}</figcaption></figure>
</div></section>
<section class="sec"><div class="container">
    <div class="warn-box"><strong>{{ $locale === 'bn' ? 'অ্যাপ্লায়েন্স ডেভেলপমেন্ট ক্যান্ডিডেট' : 'Development appliance; production-configured management' }}</strong><p>{{ $locale === 'bn' ? 'WinControl সার্ভার production কনফিগারেশনে চালু। এটি রাউটার ইমেজকে production-ready করে না। বাস্তব USB পেলোড শনাক্তকরণ, হার্ডওয়্যার যোগ্যতা ও বাণিজ্যিক অ্যাক্টিভেশন অসম্পূর্ণ; firmware A/B rollback নেই।' : 'WinControl is deployed with production configuration. The appliance remains a development candidate for isolated evaluation: physical USB discovery, hardware qualification and commercial activation remain unresolved or unqualified. Automatic firmware A/B rollback is unsupported.' }}</p><a href="{{ route('docs.show', ['slug' => 'release', 'lang' => $locale]) }}">{{ $locale === 'bn' ? 'সম্পূর্ণ যাচাইয়ের পরিসর ও সীমাবদ্ধতা' : 'Read the exact qualification scope and limitations' }} →</a></div>
    <div class="feature-mosaic" style="margin-top:28px"><div class="cols cols-2">
        @foreach ($features as $feature)
            <article class="card"><span class="badge badge-line">{{ $feature['status'] }}</span><h2>{{ $feature['title'] }}</h2><p>{{ $feature['description'] }}</p></article>
        @endforeach
    </div></div>
</div></section>
<section class="sec"><div class="container"><div class="sec-head"><h2>{{ $locale === 'bn' ? 'অপারেটরের জন্য প্রকাশিত তথ্য' : 'Published for operators' }}</h2><p>{{ $locale === 'bn' ? 'সংস্করণ, SHA-256, বাস্তব স্ক্রিনশট, সোর্স পরিচয় এবং পরীক্ষার সীমা দেখুন।' : 'Versioned downloads, SHA-256 checksums, real screenshots, reviewed source identities and explicit test boundaries.' }}</p></div><div class="cols cols-3">
    <article class="card"><h3>{{ $i18n['nav_downloads'] }}</h3><p>{{ $release['qualification'] }}. Not production-ready or bare-metal qualified.</p><a class="more" href="{{ route('downloads', ['lang' => $locale]) }}">{{ $i18n['cta_download'] }} →</a></article>
    <article class="card"><h3>{{ $i18n['nav_docs'] }}</h3><p>{{ $locale === 'bn' ? 'ইনস্টল, বহিরাগত ম্যানেজমেন্ট, ARP, Safe Mode ও পুনরুদ্ধারের নথি।' : 'Installation, external management, ARP, Safe Mode and recovery guides.' }}</p><a class="more" href="{{ route('docs.index', ['lang' => $locale]) }}">{{ $i18n['cta_docs'] }} →</a></article>
    <article class="card"><h3>{{ $locale === 'bn' ? 'সোর্স ও লাইসেন্স' : 'Source and license notices' }}</h3><p>{{ $support['source_status'] }}</p><a class="more" href="{{ route('docs.show', ['slug' => 'source', 'lang' => $locale]) }}">{{ $locale === 'bn' ? 'বিল্ড ইনপুট ও সীমা' : 'Build inputs and boundaries' }} →</a></article>
</div></div></section>
@endsection
