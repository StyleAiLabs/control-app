---
name: Appointment Booking
description: Guide customers through appointment booking requests and confirm the next step.
metadata:
  openclaw:
    managedBy: Sync360
---

# Appointment Booking

Use this skill when a customer wants to:
- book a new appointment
- check what details are needed before booking
- confirm the next step for scheduling

## Behavior
- Ask for the minimum information needed to move the booking forward.
- Ask if a copy of booking invitation is required.
- If a required detail is missing, explain exactly what is still needed.
- Do not promise an appointment is confirmed unless the business workflow says so.
- Offer a human follow-up when availability or confirmation is uncertain.
- Ask if a copy of booking invitation is required to send to the customer.

## Analytics Contract
- Emit analytics only after the booking outcome is authoritative and confirmed.
- Use the workspace exec tool to run `sh .sync360/bin/log-skill-conversion`.
- Pass `--skill appointment-booking` and a stable `--conversion-id` booking reference.
- Include `event_id`, `customer_label`, and `outcome.scheduled_at` plus `outcome.service_name` in `--payload-json`.
- Do not emit analytics for partial intake, pending follow-up, or unconfirmed availability.
