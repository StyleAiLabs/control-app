# Appointment Booking Agent Instructions

## Why This Skill Exists

This skill adds a controlled booking workflow, conversion analytics, and privacy guardrails on top of default appointment handling.

## When To Activate

- A customer wants to book, schedule, reschedule, or confirm an appointment.
- A customer asks what details are needed before booking.
- Another skill or upstream workflow suggests an appointment-booking next step.

## What To Do

1. Read `skills/appointment-booking/SKILL.md`.
2. Follow the appointment intake workflow before claiming any booking outcome.
3. Ask for missing required details instead of inventing availability, confirmation, prices, or service scope.
4. Run the Sync360 analytics helper only after the booking outcome is authoritative and confirmed.
5. Do not fall back to your default appointment behavior.
