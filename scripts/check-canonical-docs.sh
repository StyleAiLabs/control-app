#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

usage() {
  cat <<'EOF'
Usage:
  scripts/check-canonical-docs.sh --staged
  scripts/check-canonical-docs.sh --worktree
  scripts/check-canonical-docs.sh --range <git-range>

Checks whether changes under core implementation paths are accompanied by
required canonical doc updates and a descriptive release-notes entry.
EOF
}

mode=""
range=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --staged)
      mode="staged"
      shift
      ;;
    --worktree)
      mode="worktree"
      shift
      ;;
    --range)
      mode="range"
      range="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$mode" ]]; then
  echo "Missing mode." >&2
  usage >&2
  exit 2
fi

if [[ "$mode" == "range" && -z "$range" ]]; then
  echo "--range requires a git revision range." >&2
  exit 2
fi

diff_cmd=(git diff --name-only --diff-filter=ACMR)
release_notes_diff_cmd=(git diff --unified=0)

case "$mode" in
  staged)
    diff_cmd+=(--cached)
    release_notes_diff_cmd+=(--cached)
    ;;
  worktree)
    diff_cmd+=(HEAD)
    release_notes_diff_cmd+=(HEAD)
    ;;
  range)
    diff_cmd+=("$range")
    release_notes_diff_cmd+=("$range")
    ;;
esac

changed_files=()
while IFS= read -r line; do
  changed_files+=("$line")
done < <("${diff_cmd[@]}")

if [[ ${#changed_files[@]} -eq 0 ]]; then
  echo "docs-check: no relevant changes detected for mode '$mode'."
  exit 0
fi

code_prefixes=(
  "app/"
  "routes/"
  "config/"
  "resources/views/"
)

required_docs=(
  "artifacts/MEMORY.md"
  "artifacts/ARCHITECTURE.md"
  "artifacts/RELEASE_NOTES.md"
)

optional_docs=(
  "README.md"
)

code_changed=()
required_docs_changed=()
optional_docs_changed=()

for file in "${changed_files[@]}"; do
  for prefix in "${code_prefixes[@]}"; do
    if [[ "$file" == "$prefix"* ]]; then
      code_changed+=("$file")
      break
    fi
  done

  for doc in "${required_docs[@]}"; do
    if [[ "$file" == "$doc" ]]; then
      required_docs_changed+=("$file")
      break
    fi
  done

  for doc in "${optional_docs[@]}"; do
    if [[ "$file" == "$doc" ]]; then
      optional_docs_changed+=("$file")
      break
    fi
  done
done

if [[ ${#code_changed[@]} -eq 0 ]]; then
  echo "docs-check: no core code changes in app/, routes/, config/, or resources/views/."
  exit 0
fi

missing_docs=()
for doc in "${required_docs[@]}"; do
  found="false"
  for changed in "${required_docs_changed[@]}"; do
    if [[ "$changed" == "$doc" ]]; then
      found="true"
      break
    fi
  done

  if [[ "$found" != "true" ]]; then
    missing_docs+=("$doc")
  fi
done

release_notes_has_descriptive_addition="false"
while IFS= read -r line; do
  [[ "$line" == +++* ]] && continue
  [[ "$line" != +* ]] && continue

  content="${line#?}"
  trimmed="$(printf '%s' "$content" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"

  [[ -z "$trimmed" ]] && continue
  [[ "$trimmed" == '---' ]] && continue
  [[ "$trimmed" == '```' ]] && continue
  [[ "$trimmed" == \#* ]] && continue
  [[ "$trimmed" == Date:* ]] && continue
  [[ "$trimmed" == Branch:* ]] && continue
  [[ "$trimmed" == Status:* ]] && continue
  [[ "$trimmed" == '>'* ]] && continue

  normalized="$(printf '%s' "$trimmed" | sed -E 's/^[-*[:digit:].[:space:]]+//')"

  if [[ ${#normalized} -ge 20 && "$normalized" =~ [A-Za-z]{3,} ]]; then
    release_notes_has_descriptive_addition="true"
    break
  fi
done < <("${release_notes_diff_cmd[@]}" -- artifacts/RELEASE_NOTES.md)

if [[ ${#missing_docs[@]} -eq 0 && "$release_notes_has_descriptive_addition" == "true" ]]; then
  echo "docs-check: passed."
  echo "  code changes: ${#code_changed[@]}"
  echo "  required docs updated: ${required_docs[*]}"
  if [[ ${#optional_docs_changed[@]} -gt 0 ]]; then
    echo "  optional docs touched: ${optional_docs_changed[*]}"
  fi
  exit 0
fi

echo "docs-check: failed."
echo
echo "Detected changes under the core implementation paths:"
for file in "${code_changed[@]:0:12}"; do
  echo "  - $file"
done
if [[ ${#code_changed[@]} -gt 12 ]]; then
  echo "  - ...and $(( ${#code_changed[@]} - 12 )) more"
fi
echo
if [[ ${#missing_docs[@]} -gt 0 ]]; then
  echo "Missing required canonical doc updates:"
  for doc in "${missing_docs[@]}"; do
    echo "  - $doc"
  done
  echo
fi

if [[ "$release_notes_has_descriptive_addition" != "true" ]]; then
  echo "Release notes requirement not met:"
  echo "  - artifacts/RELEASE_NOTES.md must include a descriptive added line for this change"
  echo "  - headings or metadata alone are not enough"
  echo
fi

echo "Required on core implementation changes:"
for doc in "${required_docs[@]}"; do
  echo "  - $doc"
done
echo
echo "Optional when repo entrypoint/setup/operator guidance changed:"
for doc in "${optional_docs[@]}"; do
  echo "  - $doc"
done
echo
echo "Manual check commands:"
echo "  - bash scripts/check-canonical-docs.sh --staged"
echo "  - bash scripts/check-canonical-docs.sh --worktree"

exit 1
