<?php

namespace Tests\Feature;

use Tests\TestCase;

class NurRouterPagesTest extends TestCase
{
    public function test_homepage_loads_with_nurrouter_content(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('NurRouter')
            ->assertSee('Configuration, observation and recovery in one workspace')
            ->assertSee('class="feature-mosaic"', false);
    }

    public function test_public_pages_load(): void
    {
        foreach (['/features', '/downloads', '/docs', '/licensing', '/release', '/screenshots'] as $uri) {
            $this->get($uri)->assertOk();
        }
    }

    public function test_retired_product_urls_redirect_cleanly(): void
    {
        foreach (['/ccr', '/crs', '/routeros-builds', '/software'] as $uri) {
            $this->get($uri)->assertRedirect('/downloads');
        }

        $this->get('/download/winbox')->assertRedirect('/screenshots');
    }

    public function test_downloads_page_lists_all_download_categories(): void
    {
        $this->get('/downloads')
            ->assertOk()
            ->assertSee('NurRouterOS')
            ->assertSee('WinControl')
            ->assertSee('Source code')
            ->assertSee('id="routeros"', false)
            ->assertSee('id="wincontrol"', false)
            ->assertSee('id="source"', false)
            ->assertSee('id="verification"', false);
    }

    public function test_downloads_page_lists_publication_candidate_and_historical_release_links(): void
    {
        $this->get('/downloads')
            ->assertOk()
            ->assertSee('0.5.0-dev.14')
            ->assertSee('/releases/0.5.0-dev.14/nurrouteros-0.5.0-dev.14-installer.iso', false)
            ->assertSee('/releases/0.5.0-dev.14/nurrouteros-uefi-ext4.qcow2', false)
            ->assertSee('/releases/0.5.0-dev.14/payload/nurrouteros-bios-ext4.img.gz', false)
            ->assertSee('/releases/0.5.0-dev.14/nurrouteros-0.5.0-dev.14-build-source.tar.gz', false)
            ->assertSee('/releases/0.5.0-dev.14/nur-wincontrol-candidate-10-desktop-source.tar.gz', false)
            ->assertSee('/releases/0.5.0-dev.8/SHA256SUMS', false)
            ->assertSee('Not production-ready or bare-metal qualified')
            ->assertDontSee('/releases/0.5.0-dev.14/nurrouteros-bios-squashfs.qcow2', false);
    }

    public function test_dev10_is_published_only_as_a_boot_test_candidate(): void
    {
        $this->get('/downloads')
            ->assertOk()
            ->assertSee('0.5.0-dev.10 historical console-correction ISO')
            ->assertSee('/releases/0.5.0-dev.10/nurrouteros-0.5.0-dev.10-installer.iso', false)
            ->assertSee('Historical boot-test only')
            ->assertSee('Not primary')
            ->assertSee('The manually modified physical dev.10 USB is excluded.')
            ->assertDontSee('0.5.0-dev.9');

        $this->assertSame('0.5.0-dev.14', config('nurrouter.release.version'));
        $this->assertFalse(config('nurrouter.boot_candidate.primary_release'));
    }

    public function test_pages_expose_canonical_and_social_meta(): void
    {
        $this->get('/features')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="'.config('app.url').'/features">', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('name="twitter:card"', false);
    }

    public function test_sitemap_lists_public_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response
            ->assertOk()
            ->assertSee('/downloads</loc>', false)
            ->assertSee('/features</loc>', false)
            ->assertSee('/screenshots</loc>', false);

        $this->assertStringContainsString('application/xml', (string) $response->headers->get('Content-Type'));
    }

    public function test_company_blog_contact_and_legal_pages_load(): void
    {
        $pages = [
            '/about' => 'Networking software built in the open.',
            '/blog' => 'Build notes and field guidance.',
            '/contact' => 'Start with the right channel.',
            '/terms' => 'Terms of use.',
            '/privacy' => 'Privacy notice.',
        ];

        foreach ($pages as $uri => $heading) {
            $this->get($uri)
                ->assertOk()
                ->assertSee($heading)
                ->assertSee('<link rel="canonical" href="'.config('app.url').$uri.'">', false);
        }
    }

    public function test_sitemap_includes_company_blog_contact_and_legal_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        foreach (['/about', '/blog', '/contact', '/terms', '/privacy'] as $uri) {
            $response->assertSee($uri.'</loc>', false);
        }
    }

    public function test_unknown_page_renders_branded_404(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('Page not found');
    }

    public function test_security_headers_are_present(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), camera=()')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_current_release_content_does_not_link_to_a_markdown_route(): void
    {
        $this->get('/docs/release')
            ->assertOk()
            ->assertSee('/docs/qualification-dev8', false)
            ->assertDontSee('/docs/QUALIFICATION-0.5.0-dev.8.md', false);
    }

    public function test_homepage_metadata_uses_the_current_release(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('NurRouterOS 0.5.0-dev.14 on OpenWrt 24.10.0', false)
            ->assertDontSee('NurRouterOS 0.4.0-rc.4 on OpenWrt 24.10.0', false);
    }

    public function test_bangla_navigation_and_overview_are_available(): void
    {
        $this->get('/?lang=bn')->assertOk()
            ->assertSee('<html lang="bn">', false)
            ->assertSee('নেটওয়ার্ক পরিচালনায় দৃশ্যমান নিয়ন্ত্রণ।')
            ->assertSee('/downloads?lang=bn', false);
        $this->get('/downloads?lang=bn')->assertOk()->assertSee('ডাউনলোড');
        $this->get('/?lang=unsupported')->assertOk()->assertSee('<html lang="en">', false);
        $this->get('/?lang[]=bn')->assertOk()->assertSee('<html lang="en">', false);
    }

    public function test_all_listed_guides_render_and_unknown_or_traversal_paths_are_rejected(): void
    {
        foreach (config('nurrouter.docs') as $doc) {
            $this->get('/docs/'.$doc['slug'])->assertOk()->assertSee($doc['title']);
        }
        $this->get('/docs/not-a-guide')->assertNotFound();
        $this->get('/docs/%2e%2e%2f.env')->assertNotFound();
    }

    public function test_public_website_does_not_expose_management_actions(): void
    {
        foreach (['/dashboard', '/routers', '/backups'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->post('/licensing/keys', [])->assertNotFound();
    }

    public function test_release_discloses_qualification_and_factory_cleanup_boundaries(): void
    {
        $this->get('/docs/release')->assertOk()
            ->assertSee('PPPoE lab credential')
            ->assertSee('Physical USB payload discovery remains unresolved')
            ->assertSee('No dev.14 soak or subscriber-capacity result is inherited');
        $this->get('/screenshots')->assertOk()->assertSee('wincontrol-dev13-arp.png', false)
            ->assertSee('not sixty physical devices');
    }
}
