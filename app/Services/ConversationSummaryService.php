<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Generates a concise, human-readable summary of a conversation session
 * via the tenant's own LiteLLM credential boundary.
 *
 * LiteLLM exposes an OpenAI-compatible /chat/completions endpoint.
 * Summaries are stored in ConversationLog.ai_summary (same value for all
 * records in a session and are available for internal Sync360 operational use.
 */
class ConversationSummaryService
{
    private const MODEL      = 'claude-sonnet-4-6';
    private const MAX_TOKENS = 150;
    /**
     * Max characters to keep from each assistant turn.
     * Prevents long service-list replies from dominating the transcript
     * and causing Claude to summarise the offer instead of the customer's choice.
     */
    private const MAX_ASSISTANT_TURN_CHARS = 250;

    public function __construct(
        private readonly TenantRuntimeCapabilityService $runtimeCapabilities,
    ) {
    }

    /**
     * Generate a 1–2 sentence summary for a conversation session.
     *
     * @param  list<array{message_in: string, message_out: string|null}>  $messages  Chronological turns.
     * @param  string|null  $senderName  Customer display name for context.
     * @return string|null  The summary, or null if the call fails or tenant credentials are unavailable.
     */
    public function summarise(Tenant $tenant, array $messages, ?string $senderName = null): ?string
    {
        try {
            try {
                $credentials = $this->runtimeCapabilities->resolveRuntimeCredentials($tenant->fresh(['agentCustomization']));
            } catch (RuntimeException $exception) {
                Log::warning('[ConversationSummaryService] Tenant runtime credentials unavailable — skipping summary.', [
                    'tenant_id' => $tenant->tenant_id,
                    'error' => $exception->getMessage(),
                ]);

                return null;
            }

            $transcript = $this->buildTranscript($messages);

            if (trim($transcript) === '') {
                return null;
            }

            $customerLabel = $senderName ? "Customer name: {$senderName}" : 'Customer';

            $systemPrompt = <<<PROMPT
                You are a CRM note writer for an AI business assistant platform.

                Write exactly one sentence (max 30 words) that captures:
                1. What the customer SPECIFICALLY asked for, selected, or chose — not what the assistant listed as options.
                2. Any concrete action, outcome, or next step (e.g. service selected, phone number given, appointment arranged, question answered).

                Rules:
                - If the customer chose a specific service or product, name it exactly (e.g. "PPC Management", "Web Design").
                - Do not describe what the assistant offered — focus on the customer's specific request and outcome.
                - Use the customer's name if provided. Do not start with "The customer".
                - No filler phrases like "inquired about services" or "discussed offerings".
                - If only a greeting happened with no clear request, say: "Session opened with no specific request made."
                PROMPT;

            $userPrompt = "{$customerLabel}\n\nConversation transcript:\n{$transcript}\n\nWrite the CRM note:";

            $response = Http::withToken($credentials['api_key'])
                ->timeout(20)
                ->post(rtrim($credentials['base_url'], '/').'/chat/completions', [
                    'model'      => self::MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'messages'   => [
                        ['role' => 'system', 'content' => trim($systemPrompt)],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('[ConversationSummaryService] LiteLLM API error.', [
                    'tenant_id' => $tenant->tenant_id,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return null;
            }

            $summary = trim($response->json('choices.0.message.content') ?? '');

            return $summary !== '' ? $summary : null;
        } catch (Throwable $e) {
            Log::warning('[ConversationSummaryService] Exception during summary generation.', [
                'tenant_id' => $tenant->tenant_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a readable transcript from ordered message turns.
     *
     * Assistant turns are truncated to MAX_ASSISTANT_TURN_CHARS to prevent long
     * service-list replies from dominating the LLM context and causing vague
     * summaries that reflect the assistant's offer rather than the customer's choice.
     *
     * @param  list<array{message_in: string, message_out: string|null}>  $messages
     */
    private function buildTranscript(array $messages): string
    {
        $lines = [];

        foreach ($messages as $msg) {
            $in = trim($msg['message_in'] ?? '');
            if ($in !== '') {
                $lines[] = 'Customer: '.$in;
            }

            $out = trim($msg['message_out'] ?? '');
            if ($out !== '') {
                // Truncate long assistant replies so service lists etc. don't
                // overshadow what the customer specifically selected or requested.
                if (mb_strlen($out) > self::MAX_ASSISTANT_TURN_CHARS) {
                    $out = mb_substr($out, 0, self::MAX_ASSISTANT_TURN_CHARS).'…';
                }
                $lines[] = 'Assistant: '.$out;
            }
        }

        return implode("\n", $lines);
    }
}
