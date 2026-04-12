<?php

/**
 * Debug scratch: test LiteLLM call directly with scraped content
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\WebScraperService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

$url = 'https://stylesoftware.co.nz';
$separator = str_repeat('─', 80);

// Stage 1: Scrape
echo "\n{$separator}\nSTAGE 1: Scraping\n{$separator}\n";
$scraper = app(WebScraperService::class);
$pageContent = $scraper->scrape($url);
echo "✓ Scraped " . mb_strlen($pageContent) . " chars from " . substr_count($pageContent, '# Page:') . " pages\n";

// Stage 2: Call LiteLLM directly and show full response
echo "\n{$separator}\nSTAGE 2: LiteLLM raw call\n{$separator}\n";

$baseUrl = rtrim((string) config('services.litellm.base_url', ''), '/');
$token   = (string) config('services.litellm.virtual_key', '');

echo "Base URL:  {$baseUrl}\n";
echo "Token:     " . substr($token, 0, 12) . "...\n";
echo "Content length: " . mb_strlen($pageContent) . " chars\n\n";

$prompt = <<<'PROMPT'
You are extracting structured business information from scraped website content to help configure a small-business digital employee.

The content below was scraped from the business website — it may include the homepage, about page, services page, contact page, and FAQ page.

Return ONLY a valid JSON object with these keys:
{
  "business_name": "string or null",
  "trading_name": "string or null",
  "tagline": "string or null",
  "description": "string or null",
  "industry": "string or null",
  "services": ["string"],
  "target_customers": "string or null",
  "tone_hint": "friendly | professional | formal | casual | null",
  "contact_email": "string or null",
  "contact_phone": "string or null",
  "contact_mobile": "string or null",
  "physical_address": "string or null",
  "city": "string or null",
  "country": "string or null",
  "pricing_notes": "string or null"
}

Rules:
- Return raw JSON only — no markdown fences, no explanation
- Use null when a value is not clearly stated in the content
- Extract only what is present — do not invent or assume information
- Keep services as a specific array of strings drawn from the content
- Keep description plain text in 2 to 3 sentences
- tone_hint must be one of the allowed values or null

Website content:
PROMPT;

$prompt .= "\n" . $pageContent;

$http = app(HttpFactory::class);

$response = $http
    ->baseUrl($baseUrl)
    ->acceptJson()
    ->withToken($token)
    ->timeout(60)
    ->post('/chat/completions', [
        'model'       => 'claude-sonnet-4-6',
        'temperature' => 0.3,
        'max_tokens'  => 4000,
        'messages'    => [[
            'role'    => 'user',
            'content' => $prompt,
        ]],
    ]);

echo "HTTP Status: " . $response->status() . "\n\n";

if ($response->failed()) {
    echo "✗ FAILED\n\n";
    echo "Response headers:\n";
    foreach ($response->headers() as $key => $values) {
        echo "  {$key}: " . implode(', ', $values) . "\n";
    }
    echo "\nResponse body:\n";
    echo $response->body() . "\n";
} else {
    echo "✓ SUCCESS\n\n";
    $content = data_get($response->json(), 'choices.0.message.content');
    echo "Extracted content:\n";
    echo $content . "\n";
}
