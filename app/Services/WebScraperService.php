<?php

namespace App\Services;

use App\Exceptions\WebScrapingFailedException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

class WebScraperService
{
    /**
     * Maximum number of pages to fetch per site (homepage + additional).
     */
    private const MAX_PAGES = 6;

    /**
     * Maximum characters of combined content to pass to the LLM.
     * ~100k chars ≈ 25k tokens — well within Claude Sonnet's context window.
     */
    private const MAX_CONTENT_CHARS = 100000;

    /**
     * HTTP timeout in seconds for each individual page fetch.
     */
    private const SCRAPE_TIMEOUT = 20;

    /**
     * Score weight for each keyword found in a page URL path.
     * Higher score = fetched first.
     */
    private const PRIORITY_KEYWORDS = [
        'about'          => 10,
        'services'       => 10,
        'service'        => 9,
        'what-we-do'     => 9,
        'what-we-offer'  => 9,
        'contact'        => 8,
        'faq'            => 8,
        'faqs'           => 8,
        'pricing'        => 8,
        'price'          => 7,
        'rates'          => 7,
        'team'           => 6,
        'our-team'       => 6,
        'who-we-are'     => 6,
        'solutions'      => 5,
        'products'       => 5,
        'how-it-works'   => 4,
        'capabilities'   => 4,
        'our-work'       => 4,
        'portfolio'      => 3,
    ];

    /**
     * URL path substrings that indicate pages with no business knowledge value.
     */
    private const EXCLUDE_PATH_PATTERNS = [
        '/blog',
        '/news',
        '/post/',
        '/article',
        '/privacy',
        '/terms',
        '/legal',
        '/cookie',
        '/cart',
        '/shop',
        '/checkout',
        '/login',
        '/register',
        '/signup',
        '/account',
        '/admin',
        '/wp-',
        '/sitemap',
        '/cdn-cgi',
        '/.well-known',
        '/tag/',
        '/category/',
        '/author/',
        '/feed',
    ];

    public function __construct(private readonly HttpFactory $http)
    {
    }

    /**
     * Scrape a business website intelligently:
     *  1. Fetch the homepage via Jina Reader (falls back to raw HTTP).
     *  2. Parse internal links from the homepage markdown.
     *  3. Score links by business-relevance keywords (about, services, contact, faq…).
     *  4. Fetch the top-scoring pages (up to MAX_PAGES total).
     *  5. Return concatenated clean markdown, truncated to MAX_CONTENT_CHARS.
     *
     * @throws WebScrapingFailedException when even the homepage cannot be fetched.
     */
    public function scrape(string $url): string
    {
        $homepageContent = $this->fetchPage($url);

        if ($homepageContent === null) {
            throw new WebScrapingFailedException(
                'We couldn\'t read that website. Please check the URL or enter your business details manually.'
            );
        }

        $sections = [
            "# Page: {$url}\n\n{$homepageContent}",
        ];

        $additionalUrls = $this->discoverRelevantPages($homepageContent, $url);

        foreach ($additionalUrls as $pageUrl) {
            $content = $this->fetchPage($pageUrl);

            if ($content !== null && trim($content) !== '') {
                $sections[] = "# Page: {$pageUrl}\n\n{$content}";
            }

            if (count($sections) >= self::MAX_PAGES) {
                break;
            }
        }

        $combined = implode("\n\n---\n\n", $sections);

        return mb_substr($combined, 0, self::MAX_CONTENT_CHARS);
    }

    /**
     * Fetch a single page, trying Jina Reader first and falling back to a
     * direct HTTP GET with HTML stripping if Jina fails.
     */
    private function fetchPage(string $url): ?string
    {
        $content = $this->fetchViaJina($url);

        if ($content !== null) {
            return $content;
        }

        return $this->fetchViaDirect($url);
    }

    /**
     * Fetch a page via the Jina Reader API which renders JS and returns
     * clean, readable markdown. Optionally uses a Bearer API key for
     * higher rate limits (JINA_API_KEY env var).
     */
    private function fetchViaJina(string $url): ?string
    {
        try {
            $jinaBase = rtrim((string) config('services.jina.base_url', 'https://r.jina.ai'), '/');
            $apiKey = (string) config('services.jina.api_key', '');

            $headers = [
                'Accept'          => 'text/plain',
                'X-Return-Format' => 'markdown',
            ];

            if ($apiKey !== '') {
                $headers['Authorization'] = 'Bearer '.$apiKey;
            }

            $response = $this->http
                ->timeout(self::SCRAPE_TIMEOUT)
                ->withHeaders($headers)
                ->get($jinaBase.'/'.$url);

            if ($response->successful() && trim($response->body()) !== '') {
                return trim($response->body());
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Fallback: fetch the page directly and strip HTML tags to produce
     * raw readable text. Less accurate than Jina but always available.
     */
    private function fetchViaDirect(string $url): ?string
    {
        try {
            $response = $this->http
                ->timeout(self::SCRAPE_TIMEOUT)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Sync360Bot/1.0)'])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $body = $response->body();

            // Remove script and style blocks entirely before stripping tags.
            $body = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $body) ?? $body;

            $text = strip_tags($body);

            // Collapse repeated whitespace into single spaces / newlines.
            $text = (string) preg_replace('/[ \t]+/', ' ', $text);
            $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
            $text = trim($text);

            return $text !== '' ? $text : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Extract internal links from Jina's markdown output, score each by
     * business-relevance keywords, and return the top candidates sorted
     * by score descending (up to MAX_PAGES - 1 results).
     *
     * @return array<int, string>
     */
    private function discoverRelevantPages(string $markdown, string $baseUrl): array
    {
        $parsedBase = parse_url($baseUrl);
        $baseHost   = (string) ($parsedBase['host'] ?? '');
        $baseScheme = (string) ($parsedBase['scheme'] ?? 'https');
        $baseOrigin = $baseScheme.'://'.$baseHost;

        // Extract all URLs from Markdown link syntax: [text](url)
        preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/', $markdown, $matches);

        /** @var array<string, int> $candidates url → best score */
        $candidates = [];

        foreach ($matches[1] as $href) {
            $href = trim($href);

            // Skip non-navigable hrefs.
            if (
                str_starts_with($href, '#') ||
                str_starts_with($href, 'mailto:') ||
                str_starts_with($href, 'tel:') ||
                str_starts_with($href, 'javascript:')
            ) {
                continue;
            }

            // Normalise protocol-relative URLs.
            if (str_starts_with($href, '//')) {
                $href = $baseScheme.':'.$href;
            }

            // Make relative paths absolute.
            if (str_starts_with($href, '/')) {
                $href = $baseOrigin.$href;
            } elseif (! str_starts_with($href, 'http')) {
                continue;
            }

            // Only follow links on the same origin.
            $parsedHref = parse_url($href);

            if (($parsedHref['host'] ?? '') !== $baseHost) {
                continue;
            }

            // Build a clean URL without query string or fragment.
            $cleanPath = rtrim((string) ($parsedHref['path'] ?? '/'), '/');
            $cleanUrl  = $baseOrigin.($cleanPath ?: '');
            $cleanBase = rtrim($baseOrigin, '/');

            // Skip the homepage itself.
            if ($cleanUrl === $cleanBase || $cleanUrl === $cleanBase.'/') {
                continue;
            }

            // Skip irrelevant paths.
            $lowerPath = strtolower($cleanPath);
            $excluded  = false;

            foreach (self::EXCLUDE_PATH_PATTERNS as $pattern) {
                if (str_contains($lowerPath, $pattern)) {
                    $excluded = true;
                    break;
                }
            }

            if ($excluded) {
                continue;
            }

            // Score by keyword presence in path.
            $score = $this->scorePath($lowerPath);

            if ($score > 0) {
                $candidates[$cleanUrl] = max($candidates[$cleanUrl] ?? 0, $score);
            }
        }

        // Sort by score descending and return top (MAX_PAGES - 1) unique URLs.
        arsort($candidates);

        return array_keys(array_slice($candidates, 0, self::MAX_PAGES - 1, true));
    }

    /**
     * Return the highest keyword score for a given URL path.
     */
    private function scorePath(string $path): int
    {
        $best = 0;

        foreach (self::PRIORITY_KEYWORDS as $keyword => $points) {
            if (str_contains($path, $keyword)) {
                $best = max($best, $points);
            }
        }

        return $best;
    }
}
