<?php

namespace App\Services;

class TenantInboxMessageFilter
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $metadata
     * @return array{skip:bool, reason:?string, hints:list<string>}
     */
    public function evaluate(array $summary, array $metadata, string $body): array
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

        if (in_array($localPart, ['no-reply', 'noreply', 'donotreply', 'do-not-reply', 'mailer-daemon', 'postmaster'], true)) {
            return ['skip' => true, 'reason' => 'noise_sender:'.$localPart, 'hints' => []];
        }

        $lowerSubject = mb_strtolower($subject);

        foreach (['security alert', 'verification code', 'verify your email', 'password reset', 'delivery status notification', 'undelivered mail', 'unsubscribe', 'newsletter'] as $needle) {
            if (str_contains($lowerSubject, $needle)) {
                return ['skip' => true, 'reason' => 'noise_subject:'.$needle, 'hints' => []];
            }
        }

        if (str_contains($bodyHead, 'list-unsubscribe:') || str_contains($bodyHead, 'auto-submitted: auto-generated')) {
            return ['skip' => true, 'reason' => 'noise_auto_generated', 'hints' => []];
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
        $labels = $summary['labels'] ?? $summary['label_ids'] ?? $metadata['label_ids'] ?? [];

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
}
