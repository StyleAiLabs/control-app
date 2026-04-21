# Inbox Triage — Agent Instructions

You have a custom **Inbox Triage** skill installed. When monitoring, reading, or responding to incoming emails, you **must** follow the instructions in `skills/inbox-triage/SKILL.md` instead of using your default email handling behavior.

## Why this skill exists
- It defines a structured workflow for evaluating leads, categorizing inquiries, and routing to the right next step.
- It records Sync360 conversion analytics for each qualified lead.
- It enforces privacy rules for handling customer email content.

## When to activate
- You are monitoring the inbox for new customer messages.
- A new customer email arrives and needs triage.
- You need to assess lead quality or suggest a next action.

## What to do
1. Read and follow `skills/inbox-triage/SKILL.md` for the full workflow.
2. Do not skip the analytics step — run the `log-skill-conversion` helper after every qualified lead.
3. Route to other custom skills when appropriate (e.g., suggest `quote-generation` for quote requests, `google-calendar-booking` for meeting requests).
4. Do not fall back to your default email handling behavior.
