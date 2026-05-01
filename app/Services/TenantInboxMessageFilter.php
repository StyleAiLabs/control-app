<?php

namespace App\Services;

class TenantInboxMessageFilter
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metadata
     * @param  string|null  $connectedGoogleEmail
     * @return array{skip:bool, reason:?string, hints:list<string>}
     */
    public function evaluate(array $summary, array $metadata, string $body, ?string $connectedGoogleEmail = null): array
    {
        $labels = $this->labels($summary, $metadata);
        $from = (string) ($metadata['from'] ?? $summary['from'] ?? '');
        $subject = (string) ($metadata['subject'] ?? $summary['subject'] ?? '');
        $bodyHead = mb_strtolower(mb_substr($body, 0, 2000));

        foreach ($labels as $label) {
            $normalized = strtoupper($label);

            if (in_array($normalized, ['CATEGORY_PROMOTIONS', 'CATEGORY_SOCIAL', 'SPAM', 'TRASH', 'SENT', 'DRAFT'], true)) {
                return ['skip' => true, 'reason' => 'noise_label:'.$normalized, 'hints' => []];
            }
        }

        $localPart = $this->senderLocalPart($from);
        $senderDomain = $this->senderDomain($from) ?? '';

        if (in_array($localPart, ['no-reply', 'noreply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster'], true)) {
            return ['skip' => true, 'reason' => 'noise_sender:'.$localPart, 'hints' => []];
        }

        $senderEmail = mb_strtolower($this->emailAddress($from));
        $connectedEmail = mb_strtolower(trim((string) $connectedGoogleEmail));

        if ($senderEmail !== '' && $connectedEmail !== '' && $senderEmail === $connectedEmail) {
            return ['skip' => true, 'reason' => 'noise_sender:self', 'hints' => []];
        }

        $lowerSubject = mb_strtolower($subject);

        foreach (['security alert', 'verification code', 'verify your email', 'password reset', 'delivery status notification', 'undelivered mail', 'unsubscribe', 'newsletter'] as $needle) {
            if (str_contains($lowerSubject, $needle)) {
                return ['skip' => true, 'reason' => 'noise_subject:'.$needle, 'hints' => []];
            }
        }

        if (
            str_contains($bodyHead, 'list-unsubscribe:')
            || str_contains($bodyHead, 'list-id:')
            || str_contains($bodyHead, 'precedence: bulk')
            || str_contains($bodyHead, 'precedence: list')
            || str_contains($bodyHead, 'auto-submitted: auto-generated')
        ) {
            return ['skip' => true, 'reason' => 'noise_auto_generated', 'hints' => []];
        }

        if ($this->looksLikeMarketingBroadcast($lowerSubject, $bodyHead, $localPart, $senderDomain)) {
            return ['skip' => true, 'reason' => 'noise_marketing_broadcast', 'hints' => []];
        }

        $hints = [];

        if (
            str_contains($lowerSubject, 'contact form')
            || str_contains($lowerSubject, 'website enquiry')
            || str_contains($lowerSubject, 'website inquiry')
            || str_contains($bodyHead, 'submitted from')
        ) {
            $hints[] = 'possible_form_submission';
        }

        return ['skip' => false, 'reason' => null, 'hints' => array_values(array_unique($hints))];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    private function labels(array $summary, array $metadata): array
    {
        $labels = array_merge(
            $this->normalizeLabels($summary['labels'] ?? null),
            $this->normalizeLabels($summary['label_ids'] ?? null),
            $this->normalizeLabels($metadata['label_ids'] ?? null),
        );

        return array_values(array_unique(array_filter($labels, 'is_string')));
    }

    /**
     * @param  mixed  $labels
     * @return list<string>
     */
    private function normalizeLabels(mixed $labels): array
    {
        if (is_string($labels)) {
            $labels = preg_split('/\s*,\s*/', $labels) ?: [];
        }

        return array_values(array_filter((array) $labels, 'is_string'));
    }

    private function senderLocalPart(string $from): string
    {
        $email = $this->emailAddress($from);

        if ($email === '' || ! str_contains($email, '@')) {
            return '';
        }

        return mb_strtolower(strstr($email, '@', true) ?: '');
    }

    public function senderDomain(string $from): ?string
    {
        $email = $this->emailAddress($from);

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        return mb_strtolower(ltrim(strstr($email, '@') ?: '', '@')) ?: null;
    }

    private function emailAddress(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return trim($matches[1]);
        }

        return trim($from);
    }

    private function looksLikeMarketingBroadcast(string $lowerSubject, string $bodyHead, string $localPart, string $senderDomain): bool
    {
        $score = 0;

        foreach ([
            'newsletter',
            'digest',
            'community edition',
            'email your agents',
            'product update',
            'feature update',
            'weekly update',
            'worth checking',
        ] as $needle) {
            if (str_contains($lowerSubject, $needle)) {
                $score += 2;
            }
        }

        foreach ([
            'introducing',
            'community',
            'announcement',
            'roundup',
            'this week',
        ] as $needle) {
            if (str_contains($lowerSubject, $needle)) {
                $score += 1;
            }
        }

        foreach ([
            'unsubscribe',
            'manage preferences',
            'view in browser',
            'read our update',
            'latest updates',
            'community edition',
            'email your agents',
        ] as $needle) {
            if (str_contains($bodyHead, $needle)) {
                $score += 1;
            }
        }

        foreach ([
            'newsletter',
            'digest',
            'updates',
            'community',
        ] as $needle) {
            if ($localPart !== '' && str_contains($localPart, $needle)) {
                $score += 1;
            }

            if ($senderDomain !== '' && str_contains($senderDomain, $needle)) {
                $score += 1;
            }
        }

        return $score >= 2;
    }
}
