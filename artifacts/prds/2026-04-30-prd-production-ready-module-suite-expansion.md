# PRD 01: Production-Ready Module Suite Expansion

Date: 2026-04-30
Type: Initiative PRD
Status: Draft

## Problem

Sync360's control-plane platform is ahead of its customer-facing module lineup. The repo currently shows two production-ready business modules, `inbox-triage` and `pdf-generation`, while the broader product promise implies a more complete automation suite for service businesses.

This creates three problems:

- new customers may see the platform as promising but narrow
- paid plans risk feeling like usage bundles rather than workflow bundles
- onboarding has limited room to personalize value by business need

## Why Now

- the platform primitives for skill catalog, assignment, rollout, analytics, and billing entitlements already exist
- the fastest way to increase commercial credibility is to deepen the workflow lineup without changing the core architecture
- Phase 1 growth is more likely to come from a stronger vertical workflow suite than from premature channel sprawl

## Goal

Expand Sync360 from a two-module workflow set into a small but compelling service-business module suite with 3-5 additional production-ready modules.

## Success Criteria

- at least 3 new production-ready business modules are shipped and assignable
- each new module has a published catalog version, rollout path, analytics contract, and operator/customer evidence path
- at least one paid plan can be marketed as a workflow bundle rather than a thin platform shell
- onboarding and billing can present a clearer set of customer-facing outcomes

## Target Users

- owner-operators of service businesses
- office/admin staff handling enquiry follow-up
- Sync360 operators managing tenant rollout and support

## In Scope

- identify and prioritize the next 3-5 service-business workflow modules
- define module-level user value, success metrics, and dependencies
- establish a production-ready bar for new modules
- define which modules should be `core` versus `featured`
- define how new modules connect to analytics, billing entitlements, and rollout operations

## Out Of Scope

- adding new customer channels in this initiative
- redesigning the entire skill framework
- fully opening the ecosystem to third-party authors

## Proposed Initial Module Candidates

- quote follow-up automation
- booking recovery / missed-enquiry recovery
- owner follow-up and escalation workflows
- simple CRM sync workflow
- accounting handoff or invoice-support workflow

## Product Requirements

- each module must map to a concrete business outcome, not just a technical capability
- each module must define required inputs, side effects, analytics events, and operator evidence
- each module must be safe to assign, unassign, publish, and roll out through the existing catalog workflow
- each module must preserve Sync360 runtime invariants, especially workspace-only `goLive()` behavior unless explicitly changed

## Dependencies

- skill catalog and rollout pipeline
- tenant assignment and runtime activation verification
- analytics contract support
- billing entitlement model

## Risks

- shipping too many weak modules instead of a few strong ones
- building modules that are technically valid but not marketable
- hidden operational burden from modules with fragile external dependencies

## Recommended Slice DAG

- `S1`: Prioritize the next 3-5 modules with customer problem statements and business ranking
- `S2`: Define the production-ready bar and shared module contract checklist
- `S3`: Ship the first featured module end-to-end
- `S4`: Ship the second featured module end-to-end
- `S5`: Update onboarding, billing, and marketing surfaces to reflect the stronger lineup

## Verification

- catalog shows the new modules as assignable and published
- at least one tenant can use each module through the intended workflow
- analytics and operator evidence are recorded for each module
- plan messaging and onboarding presentation reflect the shipped module set
