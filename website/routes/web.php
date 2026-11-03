<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

$publicPage = function (string $view, array $extra = []) {
    $requestedLocale = request()->query('lang');
    $locale = in_array($requestedLocale, ['en', 'bn'], true) ? $requestedLocale : 'en';

    return view($view, array_merge([
        'brand' => config('nurrouter.brand'),
        'release' => config('nurrouter.release'),
        'bootCandidate' => config('nurrouter.boot_candidate'),
        'stats' => config('nurrouter.stats'),
        'features' => config('nurrouter.features'),
        'featureMatrix' => config('nurrouter.feature_matrix'),
        'limitations' => config('nurrouter.limitations'),
        'licensingPlans' => config('nurrouter.licensing_plans'),
        'docs' => config('nurrouter.docs'),
        'screenshots' => config('nurrouter.screenshots'),
        'support' => config('nurrouter.support'),
        'i18n' => config('nurrouter.i18n.'.$locale),
        'locale' => $locale,
    ], $extra));
};

Route::get('/', fn () => $publicPage('home'))->name('home');
Route::get('/features', fn () => $publicPage('features'))->name('features');
Route::get('/downloads', fn () => $publicPage('downloads'))->name('downloads');
Route::get('/licensing', fn () => $publicPage('licensing'))->name('licensing');
Route::get('/release', fn () => $publicPage('release'))->name('release');
Route::get('/screenshots', fn () => $publicPage('screenshots'))->name('screenshots');
Route::get('/about', fn () => $publicPage('about'))->name('about');
Route::get('/blog', fn () => $publicPage('blog'))->name('blog');
Route::get('/contact', fn () => $publicPage('contact'))->name('contact');
Route::get('/terms', fn () => $publicPage('terms'))->name('terms');
Route::get('/privacy', fn () => $publicPage('privacy'))->name('privacy');

Route::get('/docs', fn () => $publicPage('docs-index'))->name('docs.index');
Route::get('/docs/{slug}', function (string $slug) use ($publicPage) {
    $doc = collect(config('nurrouter.docs'))->firstWhere('slug', $slug);
    abort_unless($doc, 404);

    $path = public_path('media/docs/'.$doc['file']);
    abort_unless(is_file($path), 404);

    $markdown = file_get_contents($path);
    // Strip env-var names that look like secret material from HTML rendering footnotes only if needed —
    // docs intentionally name NUR_LICENSE_SIGNING_PRIVATE_KEY as an operator configuration key.

    return $publicPage('docs-show', [
        'doc' => $doc,
        'markdown' => $markdown,
        'html' => Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]),
    ]);
})->where('slug', '[a-z0-9-]+')->name('docs.show');

// Retired aspirational product URLs → honest downloads/docs.
Route::redirect('/ccr', '/downloads', 301);
Route::redirect('/crs', '/downloads', 301);
Route::redirect('/routeros-builds', '/downloads', 301);
Route::redirect('/software', '/downloads', 301);
Route::redirect('/download/winbox', '/screenshots', 301);

Route::get('/sitemap.xml', function () {
    $base = rtrim(config('app.url'), '/');
    $pages = [
        ['path' => '/', 'priority' => '1.0'],
        ['path' => '/features', 'priority' => '0.9'],
        ['path' => '/downloads', 'priority' => '0.9'],
        ['path' => '/docs', 'priority' => '0.9'],
        ['path' => '/licensing', 'priority' => '0.8'],
        ['path' => '/release', 'priority' => '0.8'],
        ['path' => '/screenshots', 'priority' => '0.7'],
        ['path' => '/about', 'priority' => '0.6'],
        ['path' => '/blog', 'priority' => '0.7'],
        ['path' => '/contact', 'priority' => '0.5'],
        ['path' => '/terms', 'priority' => '0.3'],
        ['path' => '/privacy', 'priority' => '0.3'],
    ];
    foreach (config('nurrouter.docs') as $doc) {
        $pages[] = ['path' => '/docs/'.$doc['slug'], 'priority' => '0.7'];
    }

    $lastmod = now()->toDateString();
    $urls = '';
    foreach ($pages as $page) {
        $urls .= sprintf(
            "  <url>\n    <loc>%s</loc>\n    <lastmod>%s</lastmod>\n    <changefreq>weekly</changefreq>\n    <priority>%s</priority>\n  </url>\n",
            e($base.($page['path'] === '/' ? '' : $page['path'])),
            $lastmod,
            $page['priority'],
        );
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
        .$urls
        .'</urlset>'."\n";

    return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
})->name('sitemap');
