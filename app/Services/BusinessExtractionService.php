<?php

namespace App\Services;

use App\Exceptions\ExtractionFailedException;
use App\Models\Tenant;
use App\Exceptions\WebScrapingFailedException;
use App\Models\BusinessProfile;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

class BusinessExtractionService
{
    private const MODEL = 'claude-sonnet-4-6';

    private const EXTRACTION_PROMPT = <<<'PROMPT'
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
{{PAGE_CONTENT}}
PROMPT;

    private const FILE_GENERATION_PROMPT = <<<'PROMPT'
You are generating internal markdown configuration files for a small-business digital employee.

Return ONLY a valid JSON object with exactly these keys:
{
  "identity": "markdown string",
  "soul": "markdown string",
  "user": "markdown string",
  "bootstrap": "markdown string"
}

Business profile:
{{PROFILE_JSON}}

Selected tone:
{{TONE}}

Enabled modules:
{{MODULES}}

Rules:
- Return raw JSON only
- Keep each markdown file concise and practical
- Make the content grounded in the provided business details
- Do not invent specific pricing, legal promises, or unavailable services
PROMPT;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly WebScraperService $scraper,
        private readonly TenantRuntimeCapabilityService $runtimeCapabilities,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function extractFromUrl(Tenant $tenant, string $url): array
    {
        // Stage 1: scrape the site — homepage + up to 5 scored internal pages.
        try {
            $pageContent = $this->scraper->scrape($url);
        } catch (WebScrapingFailedException $exception) {
            throw new ExtractionFailedException($exception->getMessage());
        }

        // Stage 2: send scraped text to Claude for structured JSON extraction.
        try {
            $credentials = $this->tenantCredentials($tenant);
        } catch (RuntimeException) {
            throw new ExtractionFailedException('AI extraction is not configured for this workspace yet. You can still fill in your business details manually.');
        }

        $response = $this->http
            ->baseUrl($credentials['base_url'])
            ->acceptJson()
            ->withToken($credentials['api_key'])
            ->post('/chat/completions', [
                'model' => self::MODEL,
                'temperature' => 0.3,
                'max_tokens' => 4000,
                'messages' => [[
                    'role' => 'user',
                    'content' => str_replace('{{PAGE_CONTENT}}', $pageContent, self::EXTRACTION_PROMPT),
                ]],
            ]);

        if ($response->failed()) {
            throw new ExtractionFailedException('We couldn\'t extract your business details just now. You can try again or enter the details manually.');
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || trim($content) === '') {
            throw new ExtractionFailedException('We couldn\'t understand the extraction response. You can still fill in the details manually.');
        }

        $decoded = json_decode($this->cleanJson($content), true);

        if (! is_array($decoded)) {
            throw new ExtractionFailedException('We couldn\'t parse the extraction result. Please fill in the details manually.');
        }

        $services = array_values(array_filter(
            array_map(
                static fn (mixed $service): ?string => is_string($service) && trim($service) !== '' ? trim($service) : null,
                is_array($decoded['services'] ?? null) ? $decoded['services'] : []
            )
        ));

        return [
            'business_name' => $this->nullableString($decoded['business_name'] ?? null),
            'trading_name' => $this->nullableString($decoded['trading_name'] ?? null),
            'tagline' => $this->nullableString($decoded['tagline'] ?? null),
            'description' => $this->nullableString($decoded['description'] ?? null),
            'industry' => $this->nullableString($decoded['industry'] ?? null),
            'services' => $services,
            'target_customers' => $this->nullableString($decoded['target_customers'] ?? null),
            'tone_hint' => $this->nullableTone($decoded['tone_hint'] ?? null),
            'contact_email' => $this->nullableString($decoded['contact_email'] ?? null),
            'contact_phone' => $this->nullableString($decoded['contact_phone'] ?? null),
            'contact_mobile' => $this->nullableString($decoded['contact_mobile'] ?? null),
            'physical_address' => $this->nullableString($decoded['physical_address'] ?? null),
            'city' => $this->nullableString($decoded['city'] ?? null),
            'country' => $this->nullableString($decoded['country'] ?? null),
            'pricing_notes' => $this->nullableString($decoded['pricing_notes'] ?? null),
            'website_url' => $url,
            'website_extraction_raw' => $response->json(),
        ];
    }

    /**
     * @param  array<int, array{skill_key:string,label:string,description:?string,onboarding_role?:string}>  $modules
     * @return array{identity:string,soul:string,user:string,bootstrap:string}
     */
    public function generateAgentFiles(Tenant $tenant, BusinessProfile $profile, string $tone, array $modules): array
    {
        $payload = $this->profilePayload($profile);
        try {
            $credentials = $this->tenantCredentials($tenant);
        } catch (RuntimeException) {
            return $this->fallbackFiles($payload, $tone, $modules);
        }

        try {
            $response = $this->http
                ->baseUrl($credentials['base_url'])
                ->acceptJson()
                ->withToken($credentials['api_key'])
                ->post('/chat/completions', [
                    'model' => self::MODEL,
                    'temperature' => 0.4,
                    'max_tokens' => 5000,
                    'messages' => [[
                        'role' => 'user',
                        'content' => str_replace(
                            ['{{PROFILE_JSON}}', '{{TONE}}', '{{MODULES}}'],
                            [
                                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                                $tone,
                                json_encode(array_values($modules), JSON_UNESCAPED_SLASHES),
                            ],
                            self::FILE_GENERATION_PROMPT,
                        ),
                    ]],
                ]);

            if ($response->failed()) {
                return $this->fallbackFiles($payload, $tone, $modules);
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($content) || trim($content) === '') {
                return $this->fallbackFiles($payload, $tone, $modules);
            }

            $decoded = json_decode($this->cleanJson($content), true);

            if (! is_array($decoded)) {
                return $this->fallbackFiles($payload, $tone, $modules);
            }

            $identity = $this->nullableString($decoded['identity'] ?? null);
            $soul = $this->nullableString($decoded['soul'] ?? null);
            $user = $this->nullableString($decoded['user'] ?? null);
            $bootstrap = $this->nullableString($decoded['bootstrap'] ?? null);

            if (! $identity || ! $soul || ! $user || ! $bootstrap) {
                return $this->fallbackFiles($payload, $tone, $modules);
            }

            return [
                'identity' => $identity,
                'soul' => $soul,
                'user' => $user,
                'bootstrap' => $bootstrap,
            ];
        } catch (Throwable) {
            return $this->fallbackFiles($payload, $tone, $modules);
        }
    }

    /**
     * @return array{api_key:string, base_url:string}
     */
    private function tenantCredentials(Tenant $tenant): array
    {
        return $this->runtimeCapabilities->resolveRuntimeCredentials($tenant->fresh(['agentCustomization']));
    }

    private function cleanJson(string $content): string
    {
        $trimmed = trim($content);

        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }

        return trim($trimmed);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nullableTone(mixed $value): ?string
    {
        $tone = $this->nullableString($value);

        return in_array($tone, ['friendly', 'professional', 'formal', 'casual'], true)
            ? $tone
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function profilePayload(BusinessProfile $profile): array
    {
        return [
            'business_name' => $profile->business_name,
            'trading_name' => $profile->trading_name,
            'website_url' => $profile->website_url,
            'industry' => $profile->industry,
            'description' => $profile->description,
            'tagline' => $profile->tagline,
            'contact_email' => $profile->contact_email,
            'contact_phone' => $profile->contact_phone,
            'contact_mobile' => $profile->contact_mobile,
            'physical_address' => $profile->physical_address,
            'city' => $profile->city,
            'country' => $profile->country,
            'owner_name' => $profile->owner_name,
            'services' => is_array($profile->services) ? array_values($profile->services) : [],
            'target_customers' => $profile->target_customers,
            'pricing_notes' => $profile->pricing_notes,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array{skill_key:string,label:string,description:?string,onboarding_role?:string}>  $modules
     * @return array{identity:string,soul:string,user:string,bootstrap:string}
     */
    private function fallbackFiles(array $payload, string $tone, array $modules): array
    {
        $businessName = $payload['business_name'] ?: 'This business';
        $tagline = $payload['tagline'] ? trim((string) $payload['tagline']) : null;
        $description = $payload['description'] ?: 'This business serves customers with clear, helpful support.';
        $industry = $payload['industry'] ?: 'General services';
        $contactBits = array_values(array_filter([
            $payload['contact_email'] ? 'Email: '.$payload['contact_email'] : null,
            $payload['contact_phone'] ? 'Phone: '.$payload['contact_phone'] : null,
        ]));
        $services = is_array($payload['services']) ? array_values(array_filter(array_map(fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null, $payload['services']))) : [];
        $moduleLines = $this->moduleDescriptions($modules);
        $style = $this->toneDescription($tone);
        $targetCustomers = $payload['target_customers'] ?: 'Customers looking for fast, clear help.';
        $pricingNotes = $payload['pricing_notes'] ?: 'If pricing is requested and not already confirmed, guide the customer toward direct follow-up.';

        $identity = implode(PHP_EOL, array_filter([
            '# Agent Identity',
            '',
            '## Who I Am',
            sprintf('I am the digital assistant for **%s**.', $businessName),
            $tagline,
            '',
            '## My Role',
            $description,
            '',
            '## Industry',
            $industry,
            '',
            '## Contact',
            ...($contactBits !== [] ? array_map(fn (string $line): string => '- '.$line, $contactBits) : ['- Contact details will be confirmed during setup.']),
        ])).PHP_EOL;

        $soul = implode(PHP_EOL, [
            '# Agent Soul',
            '',
            '## Communication Style',
            $style,
            '',
            '## Core Values',
            '- Be clear, honest, and respectful.',
            '- Never promise something the business has not confirmed.',
            '- When unsure, collect details and offer a human follow-up.',
            '',
            '## What I Will Do',
            ...array_map(fn (string $line): string => '- '.$line, $moduleLines),
            '',
            '## What I Will Not Do',
            '- I will not invent pricing, availability, or business rules.',
            '- I will not discuss internal business information.',
            '- I will not make commitments outside the enabled modules and business details.',
        ]).PHP_EOL;

        $user = implode(PHP_EOL, [
            '# User Context',
            '',
            '## Who I Am Talking To',
            $targetCustomers,
            '',
            '## How I Should Help',
            '- Assume the customer wants a fast and clear answer.',
            '- Use plain language and confirm the next step.',
            '- Thank them for reaching out and make the response easy to act on.',
        ]).PHP_EOL;

        $bootstrap = implode(PHP_EOL, [
            '# Bootstrap',
            '',
            '## Business',
            sprintf('- Name: %s', $businessName),
            sprintf('- Industry: %s', $industry),
            '',
            '## Description',
            $description,
            '',
            '## Enabled Modules',
            ...array_map(fn (string $line): string => '- '.$line, $moduleLines),
            '',
            '## Services',
            ...($services !== [] ? array_map(fn (string $service): string => '- '.$service, $services) : ['- Services will be confirmed during setup.']),
            '',
            '## Contact Details',
            ...($contactBits !== [] ? array_map(fn (string $line): string => '- '.$line, $contactBits) : ['- Contact details will be confirmed during setup.']),
            '',
            '## Pricing Notes',
            $pricingNotes,
        ]).PHP_EOL;

        return [
            'identity' => $identity,
            'soul' => $soul,
            'user' => $user,
            'bootstrap' => $bootstrap,
        ];
    }

    /**
     * @param  array<int, array{skill_key:string,label:string,description:?string,onboarding_role?:string}>  $modules
     * @return array<int, string>
     */
    private function moduleDescriptions(array $modules): array
    {
        $lines = [];

        foreach ($modules as $module) {
            $label = is_string($module['label'] ?? null) && trim((string) $module['label']) !== ''
                ? trim((string) $module['label'])
                : ucfirst(str_replace('-', ' ', (string) ($module['skill_key'] ?? 'Module')));
            $description = is_string($module['description'] ?? null) && trim((string) $module['description']) !== ''
                ? trim((string) $module['description'])
                : null;
            $lines[] = $description ? sprintf('%s: %s', $label, $description) : $label;
        }

        return $lines !== [] ? $lines : ['Provide general help based on the business information available.'];
    }

    private function toneDescription(string $tone): string
    {
        return match ($tone) {
            'friendly' => 'Warm, approachable, and easy to talk to without sounding casual in a sloppy way.',
            'professional' => 'Clear, polished, and business-appropriate while staying easy to understand.',
            'formal' => 'Precise, reserved, and structured with careful wording.',
            'casual' => 'Relaxed, upbeat, and natural while still being helpful and respectful.',
            default => 'Clear, friendly, and easy to understand.',
        };
    }
}
