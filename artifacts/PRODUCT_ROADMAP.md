# Sync360 Product Roadmap

Date: 2026-04-30
Status: Codebase-derived product roadmap

## Executive Summary

Sync360 already has a real Phase 1 product, not just a prototype. The repo supports the full control-plane loop from landing page and signup through tenant provisioning, guided onboarding, live workspace deployment, Google Workspace connectivity, inbox-triage automation, workspace knowledge management, billing, and admin observability.

The clearest current product shape is:

- Sync360 is an AI digital employee platform for service businesses.
- The first commercially credible wedge is Gmail-based inbox triage plus business-profile/workspace knowledge plus document generation.
- The platform layer for future expansion is already present: skill catalog, tenant assignment/rollout, runtime capability management, billing entitlements, analytics, and cost observability.

## Evidence Reviewed

Primary repo evidence for this roadmap:

- `README.md`
- `artifacts/ARCHITECTURE.md`
- `artifacts/RELEASE_NOTES.md`
- `routes/web.php`
- `routes/console.php`
- `config/sync360.php`
- `app/Http/Controllers/*`
- `app/Services/*`
- `resources/views/*`
- `resources/skill-packs/*`

## Product Positioning

### Current target customer

Service businesses that lose revenue and time to message handling, follow-up, quoting, and repetitive admin.

### Current core value proposition

"Sync360 keeps every enquiry moving" is already consistent across the marketing site and shipped functionality. The product is strongest today when framed as:

- capture inbound business context quickly
- configure a business-aware digital employee
- connect Google Workspace
- triage live inbox work
- generate customer-ready documents
- give operators visibility into outcomes, usage, and runtime health

### Current product wedge

The most believable beachhead is:

1. Gmail inbox triage for service businesses
2. business knowledge grounding via onboarding, profile, and workspace content
3. operational follow-through via PDF generation, Drive, Sheets, and Gmail

That wedge is meaningfully narrower than a generic "AI employee for every channel," which is good for Phase 1.

## Phase 1: MVP

### Phase 1 goal

Launch a paid, self-serve control plane that can provision a tenant workspace, onboard a business, connect Google Workspace, run inbox-triage workflows safely, and meter/commercialize usage.

### Phase 1 completed

These are substantially present in the repo today.

- Marketing site, signup, login, password reset, and customer dashboard are shipped.
- Tenant creation, server assignment, async provisioning, workspace readiness, and live workspace access are shipped.
- Guided onboarding is shipped with website import, business info, tone, modules, channel, Google Workspace, and go-live steps.
- Customer profile management is shipped, including async logo upload and live agent resync.
- Customer `Workspace Content` is shipped for text, documents, and up to five reviewed website sources.
- Google Workspace OAuth, runtime auth materialization, verification, and smoke testing are shipped.
- Inbox triage as a real Sync360-owned trigger flow is shipped, including polling, filtering, idempotency, and expired-trial reply safety controls.
- Skill analytics and customer/admin reporting are shipped.
- Admin skill catalog scanning, import, publish, rollout, tenant assignment visibility, and runtime refresh flows are shipped.
- Billing foundation is shipped with Stripe Checkout, Customer Portal, plan reconciliation, interaction usage limits, and runtime gating.
- Admin runtime cost observability is shipped with LiteLLM spend import and tenant dispatch attribution.
- System health, scheduled jobs, health checks, and deploy controls are shipped.

### Phase 1 pending

These are the main gaps between the current product and a sharper, easier-to-sell MVP.

- Broader production skill lineup is still pending. The platform exists, but the repo only has two production-ready business skills today: `inbox-triage` and `pdf-generation`.
- Featured module merchandising is pending. Onboarding supports `core` and `featured` roles, but current repo skills are effectively core/test coverage rather than a differentiated module catalog.
- Customer-facing CRM/accounting integrations are pending. Billing already advertises future entitlements for `xero-myob` and `crm-integration`.

### Phase 1 pending backlog

The remaining Phase 1 gaps are now captured as initiative-level PRDs under [`artifacts/prds/`](./prds/README.md) and tracked in the Sync360 GitHub Project as `Intent Ready` initiatives.

- Production-ready module suite expansion
  PRD: [`2026-04-30-prd-production-ready-module-suite-expansion.md`](./prds/2026-04-30-prd-production-ready-module-suite-expansion.md)
  Issue: [#4](https://github.com/StyleAiLabs/control-app/issues/4) `Initiative: Expand production-ready module suite for service-business workflows`
- Module packaging and merchandising
  PRD: [`2026-04-30-prd-module-packaging-and-merchandising.md`](./prds/2026-04-30-prd-module-packaging-and-merchandising.md)
  Issue: [#5](https://github.com/StyleAiLabs/control-app/issues/5) `Initiative: Package Sync360 modules as customer-facing workflow bundles`
- First commercial integrations
  PRD: [`2026-04-30-prd-first-commercial-integrations.md`](./prds/2026-04-30-prd-first-commercial-integrations.md)
  Issue: [#7](https://github.com/StyleAiLabs/control-app/issues/7) `Initiative: Ship first commercial CRM and accounting integrations`

### MVP status call

Recommended status: **Phase 1 is functionally complete but commercially narrow.**

That means:

- the system is beyond prototype stage
- the product can support real early customers
- the go-to-market story should stay tightly focused on the Gmail/service-business wedge until more modules are live

## Phase 2: Vertical Expansion

### Goal

Turn the current platform into a small but compelling module suite for service businesses.

### Recommended themes

- Add 3-5 production-grade featured skills beyond inbox triage and PDF generation.
- Package the current workflow into clearer business outcomes such as quote follow-up, booking recovery, lead qualification, and owner follow-up.
- Ship the first two roadmap integrations already hinted in billing: CRM integration and Xero/MYOB integration.
- Make onboarding module selection feel like product packaging, not internal skill selection.
- Expand customer-facing analytics from "activity happened" to "money and time impact by workflow."
- Add the next customer channel beyond Gmail once the core wedge is commercially stronger.
- Introduce product-grade feedback loops around activation, adoption, and retention.

### Recommended outcomes

- Standard plan feels complete for one primary workflow.
- Flex plan feels meaningfully more powerful, not just higher usage limits.
- The sales story becomes "choose the workflows you want automated" instead of "we have a skill catalog."

### Phase 2 backlog

- Next channel expansion beyond Gmail
  PRD: [`2026-04-30-prd-next-channel-expansion-beyond-gmail.md`](./prds/2026-04-30-prd-next-channel-expansion-beyond-gmail.md)
  Issue: [#6](https://github.com/StyleAiLabs/control-app/issues/6) `Initiative: [Phase 2] Add the next customer channel beyond Gmail`
- Product feedback loops and adoption analytics
  PRD: [`2026-04-30-prd-product-feedback-loops-and-adoption-analytics.md`](./prds/2026-04-30-prd-product-feedback-loops-and-adoption-analytics.md)
  Issue: [#8](https://github.com/StyleAiLabs/control-app/issues/8) `Initiative: [Phase 2] Build product feedback loops and adoption analytics`

## Phase 3: Channel Expansion

### Goal

Extend Sync360 from a Gmail-first digital employee into a true multi-channel service operations layer.

### Recommended themes

- Add additional inbound channels with the same trigger, policy, and observability discipline used for Gmail.
- Normalize cross-channel lead/event identity so one customer journey can span inbox, messaging, and follow-up workflows.
- Add a shared customer timeline combining conversations, outcomes, generated files, and operator actions.
- Strengthen direct-customer messaging controls, approvals, and audit history for higher-risk channels.

### Suggested sequencing

1. Add one next channel only.
2. Prove it operationally.
3. Reuse the same commercial gating, analytics, and skill-runtime model.

Do not expand to many channels at once; the current architecture is strongest when each new channel plugs into the same control-plane rules.

## Phase 4: Platform and Ecosystem

### Goal

Evolve Sync360 from "our workflows on your tenant" into a reusable automation platform with a stronger ecosystem moat.

### Recommended themes

- Mature the skill catalog into a real product surface with featured modules, lifecycle states, rollout safety, and stronger operator UX.
- Add partner/internal developer tooling for authoring, validating, and publishing vertical skill packs.
- Expand billing from simple plan access into entitlement bundles, add-ons, and integration-driven packaging.
- Introduce stronger tenant lifecycle tooling for support, rollback, diagnostics, and success operations.
- Build longitudinal product analytics: activation, retention, expansion, and workflow adoption.

## Suggested Prioritization

### Next 90 days

- Stay focused on the Gmail/service-business wedge.
- Launch 2-3 new production-ready featured modules.
- Ship at least one of `crm-integration` or `xero-myob`.
- Tighten customer analytics around onboarding completion, skill adoption, and conversion outcomes.
- Align landing-page claims with the channels actually live in production.

### Next 6 months

- Turn plans into clearer packaged workflow bundles.
- Add the next channel after Gmail.
- Improve admin/operator support tooling and tenant diagnostics.
- Create a more legible module marketplace inside onboarding and billing.

### Next 12 months

- Broaden into a true multi-workflow, multi-channel service automation platform.
- Add ecosystem leverage through reusable skill packs and partner-friendly packaging.
- Introduce expansion revenue through entitlements, add-ons, and vertical workflows.

## Strategic Risks

- Messaging risk: the brand promise can drift broader than the shipped product if channel claims outpace real delivery.
- Catalog risk: the platform may look more complete internally than it feels to customers if only a small number of modules are production-ready.
- Complexity risk: channel expansion before packaging discipline could create operational sprawl.
- Analytics risk: strong runtime telemetry does not automatically equal strong product learning; customer adoption metrics still need to mature.

## North Star Recommendation

Recommended north star for the current phase:

- **Qualified customer work progressed by Sync360 per tenant per month**

Supporting metrics:

- onboarding completion rate
- Google Workspace connection rate
- active tenants with at least one live module
- inbox items reviewed
- successful outcomes recorded
- estimated time saved
- paid conversion from trial
- expansion from Standard to Flex

## Final Recommendation

Treat the current codebase as a **shippable Phase 1 vertical SaaS** for service-business inbox automation, not as a generic automation platform yet.

The best roadmap is:

1. finish the wedge
2. deepen the module lineup
3. add the first commercial integrations
4. expand channels carefully
5. only then lean hard into platform positioning
