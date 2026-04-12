# Onboarding Integration

Status: Open
Updated: 2026-04-12
Branch: `cdx-feature/client-onbarding-wizard`


This file tracks the remaining onboarding-focused slices for the Sync360 Control App.

## Remaining Tasks

### 1. Onboarding And Go-Live UX Polish
- Simplify Step 5 and Step 6 copy further so non-technical customers always know the next action.
- Improve readiness messaging when the workspace is still provisioning.
- Improve validation and error states around channel save and go-live.
- Tighten success states after the assistant goes live so customers feel setup is complete.

### 2. Channel Setup Usability Refinements
- Add copy-to-clipboard actions for WhatsApp and Telegram webhook details.
- Add clearer provider-specific instructions for Meta WhatsApp setup.
- Add clearer provider-specific instructions for Telegram bot webhook setup.
- Improve saved-channel summaries so customers can confirm what is already configured without re-entering secrets.

### 3. Conversation Export
- Add export from the conversations page.
- Support a customer-friendly export format such as CSV.
- Respect active filters when exporting conversation history.
- Include key columns like channel, sender, incoming message, reply, and timestamps.

### 4. Customer Conversation Drill-Down
- Group conversations by customer identifier or thread.
- Add a dedicated detail view for a single customer conversation.
- Make it easier to review message history for leads, complaints, and after-hours requests.
- Show clearer message ordering and timestamps within one thread.

### 5. Dashboard Activation Polish
- Improve the dashboard “resume setup” experience for partially onboarded tenants.
- Improve post-go-live messaging so live customers immediately understand where to go next.
- Add stronger status cues around live sync state, channel status, and recent assistant activity.

### 6. Guided Onboarding CRO Pass
- Review the full onboarding flow end-to-end for friction and unclear language.
- Remove remaining technical wording where it still leaks through.
- Tighten step order, field labels, and helper copy to reduce drop-off.
- Focus on reducing time-to-live for small business owners with minimal setup knowledge.

## Build Order Recommendation

1. Onboarding And Go-Live UX Polish
2. Channel Setup Usability Refinements
3. Dashboard Activation Polish
4. Customer Conversation Drill-Down
5. Conversation Export
6. Guided Onboarding CRO Pass
