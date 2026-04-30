# PRD 03: Next Channel Expansion Beyond Gmail

Date: 2026-04-30
Type: Initiative PRD
Status: Draft

## Problem

Sync360's strongest shipped workflow today is Gmail-based inbox triage, but the broader product and marketing story implies a wider channel footprint. Expanding channels too early would create operational sprawl, but never expanding would cap the product's relevance.

## Goal

Add one carefully chosen next customer communication channel beyond Gmail using the same control-plane discipline already established for inbox triage.

## Success Criteria

- one additional channel is selected based on customer value and technical fit
- the new channel uses explicit trigger ownership, commercial gating, analytics, and operational evidence
- channel-specific failures are visible without degrading existing Gmail workflows
- marketing and onboarding can accurately describe the supported channel set

## Target Users

- service businesses that receive meaningful customer work outside Gmail
- operators supporting multi-channel tenant setups

## In Scope

- choose the next channel based on customer demand and architectural fit
- define the trigger model, identity model, safety rules, and operational evidence path
- define customer-facing setup expectations and commercial packaging implications

## Out Of Scope

- simultaneous rollout of multiple channels
- rewriting the Gmail trigger architecture
- launching a generalized omnichannel inbox

## Product Requirements

- new channel support must reuse commercial gating and verification patterns where possible
- direct-customer messaging rules must remain explicit and auditable
- the channel must have a clear customer setup path and support playbook
- the rollout must not weaken Gmail reliability or tenant safety

## Recommended Candidate Evaluation Criteria

- real customer demand in the target service-business segment
- compatibility with current tenant runtime model
- reliability and observability of trigger delivery
- support burden and failure modes
- business value relative to implementation complexity

## Dependencies

- current runtime dispatch and analytics model
- onboarding and billing packaging decisions
- operator support tooling

## Risks

- taking on a channel whose provider contracts are brittle
- overextending the product story before operational maturity
- fragmenting analytics and customer journey evidence across channels

## Recommended Slice DAG

- `S1`: Run a channel selection spike and choose the next channel
- `S2`: Define the trigger, identity, and safety contract for that channel
- `S3`: Implement one end-to-end tenant setup and workflow path
- `S4`: Add customer/operator evidence and update go-to-market copy

## Verification

- one tenant can configure and use the new channel end-to-end
- operator evidence clearly distinguishes channel delivery, policy, and workflow state
- the product story clearly names supported channels without ambiguity
