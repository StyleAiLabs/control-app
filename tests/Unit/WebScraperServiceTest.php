<?php

namespace Tests\Unit;

use App\Exceptions\WebScrapingFailedException;
use App\Services\WebScraperService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebScraperServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): WebScraperService
    {
        return new WebScraperService(app(HttpFactory::class));
    }

    public function test_returns_homepage_content_when_jina_succeeds(): void
    {
        Http::fake([
            'https://r.jina.ai/*' => Http::response("# Acme Plumbing\n\nAuckland's trusted plumbers.", 200),
        ]);

        $service = $this->makeService();
        $result  = $service->scrape('https://acme.example.com');

        $this->assertStringContainsString('Acme Plumbing', $result);
        $this->assertStringContainsString('Page: https://acme.example.com', $result);
    }

    public function test_discovers_and_fetches_scored_internal_pages(): void
    {
        $homepage = implode("\n", [
            '# Acme Plumbing',
            '',
            '[About Us](https://acme.example.com/about)',
            '[Our Services](https://acme.example.com/services)',
            '[Blog](https://acme.example.com/blog/post-1)',   // should be excluded
            '[Contact](https://acme.example.com/contact)',
        ]);

        Http::fake([
            'https://r.jina.ai/https://acme.example.com'          => Http::response($homepage, 200),
            'https://r.jina.ai/https://acme.example.com/about'    => Http::response('About content.', 200),
            'https://r.jina.ai/https://acme.example.com/services' => Http::response('Services content.', 200),
            'https://r.jina.ai/https://acme.example.com/contact'  => Http::response('Contact content.', 200),
            // Blog should never be fetched.
            'https://r.jina.ai/*' => Http::response('Should not be fetched.', 200),
        ]);

        $service = $this->makeService();
        $result  = $service->scrape('https://acme.example.com');

        $this->assertStringContainsString('About content.', $result);
        $this->assertStringContainsString('Services content.', $result);
        $this->assertStringContainsString('Contact content.', $result);
    }

    public function test_blog_and_excluded_paths_are_not_fetched(): void
    {
        $homepage = implode("\n", [
            '# Acme',
            '[Blog Post](https://acme.example.com/blog/intro)',
            '[Privacy Policy](https://acme.example.com/privacy)',
            '[Terms](https://acme.example.com/terms)',
        ]);

        Http::fake([
            'https://r.jina.ai/https://acme.example.com' => Http::response($homepage, 200),
        ]);

        $service = $this->makeService();
        $result  = $service->scrape('https://acme.example.com');

        // Only the homepage should appear — excluded paths produce no additional sections.
        $this->assertStringContainsString('Page: https://acme.example.com', $result);
        $this->assertStringNotContainsString('Page: https://acme.example.com/blog', $result);
        $this->assertStringNotContainsString('Page: https://acme.example.com/privacy', $result);
    }

    public function test_falls_back_to_direct_http_when_jina_fails(): void
    {
        Http::fake([
            'https://r.jina.ai/*'        => Http::response('', 500),
            'https://acme.example.com'   => Http::response('<html><body><h1>Acme Plumbing</h1></body></html>', 200),
        ]);

        $service = $this->makeService();
        $result  = $service->scrape('https://acme.example.com');

        $this->assertStringContainsString('Acme Plumbing', $result);
    }

    public function test_throws_when_both_jina_and_direct_fetch_fail(): void
    {
        Http::fake([
            'https://r.jina.ai/*'      => Http::response('', 500),
            'https://acme.example.com' => Http::response('', 500),
        ]);

        $this->expectException(WebScrapingFailedException::class);

        $service = $this->makeService();
        $service->scrape('https://acme.example.com');
    }

    public function test_output_is_truncated_to_max_content_chars(): void
    {
        $bigContent = str_repeat('x', 200000);

        Http::fake([
            'https://r.jina.ai/*' => Http::response($bigContent, 200),
        ]);

        $service = $this->makeService();
        $result  = $service->scrape('https://acme.example.com');

        // MAX_CONTENT_CHARS is 100_000 — result must not exceed that.
        $this->assertLessThanOrEqual(100000, mb_strlen($result));
    }

    public function test_does_not_follow_external_links(): void
    {
        $homepage = implode("\n", [
            '# Acme',
            '[Facebook](https://facebook.com/acme)',
            '[Instagram](https://instagram.com/acme)',
            '[Our Services](https://acme.example.com/services)',
        ]);

        Http::fake([
            'https://r.jina.ai/https://acme.example.com'          => Http::response($homepage, 200),
            'https://r.jina.ai/https://acme.example.com/services' => Http::response('Services page.', 200),
        ]);

        $service = $this->makeService();
        $result  = $service->scrape('https://acme.example.com');

        $this->assertStringContainsString('Services page.', $result);

        // Only homepage + services sections — no external domains added as separate pages.
        $this->assertStringNotContainsString('Page: https://facebook.com', $result);
        $this->assertStringNotContainsString('Page: https://instagram.com', $result);
    }

    public function test_uses_jina_api_key_when_configured(): void
    {
        config()->set('services.jina.api_key', 'jina-test-key');

        Http::fake([
            'https://r.jina.ai/*' => Http::response('Homepage content.', 200),
        ]);

        $service = $this->makeService();
        $service->scrape('https://acme.example.com');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            return str_contains($request->url(), 'r.jina.ai')
                && $request->header('Authorization') === ['Bearer jina-test-key'];
        });
    }

    public function test_no_authorization_header_sent_without_api_key(): void
    {
        config()->set('services.jina.api_key', '');

        Http::fake([
            'https://r.jina.ai/*' => Http::response('Homepage content.', 200),
        ]);

        $service = $this->makeService();
        $service->scrape('https://acme.example.com');

        Http::assertSent(function (\Illuminate\Http\Client\Request $request): bool {
            if (! str_contains($request->url(), 'r.jina.ai')) {
                return false;
            }

            return $request->header('Authorization') === [];
        });
    }
}
