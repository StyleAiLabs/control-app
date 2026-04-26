#!/usr/bin/env bash

set -euo pipefail

# Example helper only. Replace with skill-specific logic.
# This script should stay deterministic, side-effect scoped, and documented in SKILL.md.

input="${1:-}"

if [[ -z "$input" ]]; then
  echo "missing input" >&2
  exit 1
fi

printf 'normalized:%s\n' "$input"
