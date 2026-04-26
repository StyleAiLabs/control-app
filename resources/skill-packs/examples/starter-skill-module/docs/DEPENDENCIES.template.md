# Dependency Guide

## Runtime Model

- `sync360_workspace` / `openclaw_native` / `runtime_capability`

## Dependencies

For each dependency list:

- name
- type
- who provides it
- how Sync360 verifies it
- what happens if it is missing

## Module-Local Helpers

If the skill ships scripts, templates, or vendored helper code:

- list each helper
- explain what it does
- explain what runtime it expects
- explain why it does not replace a real runtime capability if one is still needed
