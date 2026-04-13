<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates a concise, human-readable summary of a conversation session
 * via the platform's own LiteLLM virtual key.
 *
 * LiteLLM exposes an OpenAI-compatible /chat/completions endpoint.
 * Summaries are stored in ConversationLog.ai_summary (same value for all
 * records in a session) and surfaced in the Conversations dashboard.
 *
 * Config keys used (services.litellm.*):
 *   LITELLM_BASE_URL    — e.g. https://litellm.stylesoftware.co.nz
 *   LITELLM_VIRTUAL_KEY — platform-level virtual key (NOT a tenant key)
 */
class ConversationSummaryService
{
    private const MODEL      = 'claude-sonnet-4-6';
    private const MAX_TOKENS = 120;

    /**
     * Generate a 1–2 sentence summary for a conversation session.
     *
     * @param  list<array{message_in: string, message_out: string|null}>  $messages  Chronological turns.
     * @param  string|null  $senderName  Customer display name for context.
     * @return string|null  The summary, or null if the call fails or is unconfigured.
     */
    public function summarise(array $messages, ?string $senderName = null): ?string
    {
        try {
            $baseUrl    = rtrim((string) config('services.litellm.base_url', ''), '/');
            $virtualKey = (string) config('services.litellm.virtual_key', '');

            if ($baseUrl === '' || $virtualKey === '') {
                Log::warning('[ConversationSummaryService] LITELLM_BASE_URL or LITELLM_VIRTUAL_KEY not configured — skipping summary.');

                return null;
            }

            $transcript = $this->buildTranscript($messages);

            if (trim($transcript) === '') {
                return null;
            }

            $customerLabel = $senderName ? "Customer: {$senderName}" : 'A customer';

            $systemPrompt = <<<PROMPT
                You are a conversation summariser for an AI business assistant platform.
                Write a single concise sentence (max 25 words) describing what the customer needed
                and how the assistant helped. Focus on the business outcome. No filler phrases.
                Do not start with "The customer" — be direct.
                PROMPT;

            $userPrompt = "{$customerLabel} had this conversation:\n\n{$transcript}\n\nSummarise in one sentence.";

            $response = Http::withToken($virtualKey)
                ->timeout(20)
                ->post("{$baseUrl}/chat/completions", [
                    'model'      => self::MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'messages'   => [
                        ['role' => 'system', 'content' => trim($systemPrompt)],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('[ConversationSummaryService] LiteLLM API error.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return null;
            }

            $summary = trim($response->json('choices.0.message.content') ?? '');

            return $summary !== '' ? $summary : null;
        } catch (Throwable $e) {
            Log::warning('[ConversationSummaryService] Exception during summary generation.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build a readable transcript from ordered message turns.
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
                $lines[] = 'Assistant: '.$out;
            }
        }

        return implode("\n", $lines);
    }
}
