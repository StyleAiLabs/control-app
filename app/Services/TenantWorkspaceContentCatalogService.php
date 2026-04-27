<?php

namespace App\Services;

class TenantWorkspaceContentCatalogService
{
    /**
     * @return array<int, array{slug:string,title:string,summary:string,placeholder:string,topic:string}>
     */
    public function textBlocks(): array
    {
        return [
            [
                'slug' => 'pricing-guidance',
                'title' => 'Pricing guidance',
                'summary' => 'Add the pricing rules, callout notes, or estimator guardrails the assistant can safely reference.',
                'placeholder' => 'Example: Standard callout fees start from $..., after-hours pricing is quoted case by case, and larger jobs should be escalated for a tailored quote.',
                'topic' => 'pricing',
            ],
            [
                'slug' => 'service-boundaries',
                'title' => 'Service boundaries',
                'summary' => 'Clarify what kinds of work you do, what you do not do, and any service-area limitations.',
                'placeholder' => 'Example: We handle residential maintenance and light commercial work, but we do not take on new-build electrical fit-outs.',
                'topic' => 'services',
            ],
            [
                'slug' => 'policy-notes',
                'title' => 'Policy notes',
                'summary' => 'Capture cancellation, warranty, payment, safety, or after-hours policies the assistant should quote carefully.',
                'placeholder' => 'Example: Cancellations inside 24 hours may incur a fee, and payment terms are due on completion unless approved otherwise.',
                'topic' => 'policy',
            ],
            [
                'slug' => 'escalation-notes',
                'title' => 'Escalation notes',
                'summary' => 'Tell the assistant when to stop answering directly and hand the conversation to a human.',
                'placeholder' => 'Example: Escalate disputes, complaints, custom commercial contracts, and any request involving a site inspection or negotiated pricing.',
                'topic' => 'escalation',
            ],
            [
                'slug' => 'common-customer-questions',
                'title' => 'Common customer questions',
                'summary' => 'Add the plain-language answers you repeat often so the assistant can respond faster.',
                'placeholder' => 'Example: Yes, we service West Auckland and North Shore. For urgent leaks after hours, collect the address and promise a next-step callback.',
                'topic' => 'faq',
            ],
        ];
    }

    /**
     * @return array<string, array{slug:string,title:string,summary:string,placeholder:string,topic:string}>
     */
    public function textBlocksBySlug(): array
    {
        $indexed = [];

        foreach ($this->textBlocks() as $block) {
            $indexed[$block['slug']] = $block;
        }

        return $indexed;
    }
}
