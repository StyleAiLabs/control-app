# Hello World (by Sync360) Agent Instructions

## Why This Skill Exists

This skill adds a controlled hello world workflow, conversion analytics, and simple response guardrails on top of default greeting behavior.

## When To Activate

- A customer asks for a hello world response.
- A customer asks to test whether a custom skill is active.
- Another skill or upstream workflow suggests a hello-world next step.

## What To Do

1. Read `skills/hello-world/SKILL.md`.
2. Respond with a friendly hello world greeting.
3. Echo back what the user said in a simple way.
4. Run the Sync360 analytics helper only after the greeting has been sent.
5. Do not fall back to your default greeting behavior.
