#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

chmod +x scripts/check-canonical-docs.sh scripts/install-git-hooks.sh .githooks/pre-commit .githooks/pre-push
git config core.hooksPath .githooks

echo "Installed local git hooks for canonical docs checks."
echo "core.hooksPath -> .githooks"
