# PRD 02: Module Packaging And Merchandising

Date: 2026-04-30
Type: Initiative PRD
Status: Draft

## Problem

Sync360 already has internal concepts for `core`, `featured`, included skills, and future entitlements, but the customer-facing product still reads more like a technical skill platform than a packaged set of business outcomes.

This creates avoidable friction:

- customers choose modules without enough framing around value
- Standard and Flex risk feeling like limit tiers instead of outcome tiers
- merchandising lags behind the architecture already present in the repo

## Goal

Turn Sync360's module selection, billing, and merchandising into a customer-facing packaging system centered on business outcomes rather than internal skill terminology.

## Success Criteria

- onboarding presents modules as clear business outcomes with strong customer language
- billing plans clearly explain live modules, add-ons, and future expansion paths
- Standard and Flex have differentiated value propositions beyond interaction limits
- admin and catalog terminology can remain technical internally without leaking into customer copy

## Target Users

- prospective customers evaluating plans
- customers in onboarding selecting modules
- operators configuring and explaining tenant capabilities

## In Scope

- package and name current and upcoming modules in customer-facing language
- redesign module presentation across onboarding and billing
- define which workflows belong in Standard, Flex, and future add-ons
- create merchandising rules for `core`, `featured`, and future entitlement modules

## Out Of Scope

- building new modules themselves
- changing core billing reconciliation mechanics
- third-party marketplace behavior

## Product Requirements

- customer copy must describe outcomes, not implementation internals
- plan packaging must stay consistent with real entitlement behavior in code
- onboarding module choice must stay compatible with current assignment and rollout architecture
- future entitlements shown in UI must map to a believable roadmap, not vague placeholder text

## Open Product Decisions

- whether Standard includes one full workflow bundle or a curated starter bundle
- whether Flex is positioned around breadth, depth, or integration access
- whether future add-ons should appear during onboarding or only after activation

## Dependencies

- billing plan catalog
- onboarding module payloads
- skill catalog metadata
- production-ready module lineup

## Risks

- repackaging too early before enough modules are live
- overselling future entitlements without delivery dates
- forcing packaging rules that do not map cleanly to actual assignment logic

## Recommended Slice DAG

- `S1`: Define customer-facing module taxonomy and plan packaging rules
- `S2`: Refresh onboarding module selection language and structure
- `S3`: Refresh billing page and upgrade surfaces around packaged workflows
- `S4`: Align marketing copy with the packaged product story

## Verification

- onboarding copy no longer depends on internal skill vocabulary
- billing surfaces describe plans as workflow bundles with clear differentiation
- operators can explain what each plan includes in one or two sentences
