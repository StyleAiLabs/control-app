# Onboarding Integration

> [!IMPORTANT]
> Historical planning note. Canonical current truth is in [`artifacts/MEMORY.md`](../../MEMORY.md) and [`artifacts/ARCHITECTURE.md`](../../ARCHITECTURE.md). The current implementation supports Telegram as the live channel path. WhatsApp remains unimplemented beyond a disabled onboarding placeholder, and older webhook-oriented setup language in this file is no longer current.

Status: Open
Updated: 2026-04-12
Branch: `cdx-feature/client-onbarding-wizard`


This file tracks the remaining onboarding-focused slices for the Sync360 Control App.

## Remaining Tasks

### 1. Onboarding And Go-Live UX Polish
- Simplify Step 5 and Step 6 copy further so non-technical customers always know the next action.
- Change the Channel copy as the Telegram/Whatsapp channel connect primary used for Business Owner to communicate with Digital Assistant / Digital Employee
- Improve readiness messaging when the workspace is still provisioning.
- Improve validation and error states around channel save and go-live.
- Tighten success states after the assistant goes live so customers feel setup is complete.

### 2. Channel Setup Usability Refinements
- Add copy-to-clipboard actions for WhatsApp and Telegram webhook details.
- Add clearer instructions for Telegram bot webhook setup.
- Improve saved-channel summaries so customers can confirm what is already configured without re-entering secrets.

### 3. Conversation Page
- Change the page copy as the Telegram/ Whatsapp channel connect primary used for Business Owner to communicate with Digital Assistant / Digital Employee
- Conversation module will be engineered at a later phase. hence at this stage show a summary of session conversation from connected channels only. 

### 4. Dashboard Activation Polish
- Improve the dashboard “resume setup” experience for partially onboarded tenants.
- Improve post-go-live messaging so live customers immediately understand where to go next.
- Add stronger status cues around live sync state, channel status, and recent assistant activity.

### 5. Guided Onboarding CRO Pass
- Review the full onboarding flow end-to-end for friction and unclear language.
- Remove remaining technical wording where it still leaks through.
- Tighten step order, field labels, and helper copy to reduce drop-off.
- Focus on reducing time-to-live for small business owners with minimal setup knowledge.

## Build Order Recommendation

1. Onboarding And Go-Live UX Polish
2. Channel Setup Usability Refinements
3. Dashboard Activation Polish
4. Conversation Export
5. Guided Onboarding CRO Pass
