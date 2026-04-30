# PRD 04: First Commercial Integrations

Date: 2026-04-30
Type: Initiative PRD
Status: Draft

## Problem

The billing model already hints at future entitlements for `crm-integration` and `xero-myob`, but these integrations are not yet productized. Without system-of-record integrations, Sync360 risks stopping at workflow assistance instead of becoming part of the customer's operating system.

## Goal

Ship the first commercially meaningful integrations so Sync360 can move qualified work and generated outputs into the systems customers already rely on.

## Success Criteria

- at least one CRM integration path is defined and productized
- at least one accounting integration path is defined and productized
- integrations are represented in billing and packaging as real entitlements, not placeholders
- operators can verify integration health and troubleshoot failures without tenant guesswork

## Target Users

- service-business owners who need work logged into existing systems
- admin/support staff who depend on CRM or accounting continuity
- Sync360 operators handling setup and support

## In Scope

- define the first CRM integration outcome and system targets
- define the first accounting integration outcome and system targets
- map integration setup, credential management, sync scope, and failure handling
- define entitlement and plan placement for these integrations

## Out Of Scope

- building a broad integrations marketplace
- deep bidirectional sync for every object type
- replacing customer CRM/accounting workflows wholesale

## Product Requirements

- each integration must solve a concrete workflow handoff problem
- setup must be understandable to a non-technical customer or operator
- admin surfaces must expose health and last-success/last-failure evidence
- entitlement rules must map cleanly to plan packaging and tenant assignment

## Suggested First Outcomes

- CRM: push qualified leads or booked work into a target CRM with enough business context for follow-up
- Accounting: generate or hand off quote/invoice-ready artifacts into a supported accounting workflow

## Dependencies

- module packaging initiative
- production-ready module expansion
- billing entitlements
- operator evidence surfaces

## Risks

- integrations becoming custom one-offs rather than reusable product features
- credential and support complexity outpacing customer value
- unclear ownership between Sync360 workflow logic and system-of-record truth

## Recommended Slice DAG

- `S1`: Select first CRM target and define exact customer outcome
- `S2`: Select first accounting target and define exact customer outcome
- `S3`: Implement one CRM integration slice end-to-end
- `S4`: Implement one accounting integration slice end-to-end
- `S5`: Align billing, onboarding, and support surfaces around the shipped integrations

## Verification

- a tenant can complete integration setup successfully
- Sync360 can produce at least one real downstream record or handoff per integration
- support/admin surfaces show integration health and failure evidence
