#!/usr/bin/env sh
set -eu

REPO_PATH="${SYNC360_CONTROL_DEPLOY_REPO_PATH:-}"
BRANCH="${SYNC360_CONTROL_DEPLOY_BRANCH:-}"
COMPOSE_FILE="${SYNC360_CONTROL_DEPLOY_COMPOSE_FILE:-docker-compose.prod.yml}"
STATUS_FILE="${SYNC360_CONTROL_DEPLOY_STATUS_FILE:-}"
LOG_FILE="${SYNC360_CONTROL_DEPLOY_LOG_FILE:-}"

if [ -z "$REPO_PATH" ] || [ -z "$BRANCH" ] || [ -z "$STATUS_FILE" ] || [ -z "$LOG_FILE" ]; then
  echo "Missing deploy configuration."
  exit 1
fi

mkdir -p "$(dirname "$STATUS_FILE")" "$(dirname "$LOG_FILE")"

timestamp() {
  date -u +"%Y-%m-%dT%H:%M:%SZ"
}

current_commit_short() {
  git -C "$REPO_PATH" rev-parse --short HEAD 2>/dev/null || true
}

current_commit_full() {
  git -C "$REPO_PATH" rev-parse HEAD 2>/dev/null || true
}

current_commit_subject() {
  git -C "$REPO_PATH" log -1 --pretty=%s 2>/dev/null || true
}

write_status() {
  cat > "$STATUS_FILE" <<EOF
state=$1
branch=$BRANCH
repo_path=$REPO_PATH
started_at=${2:-}
finished_at=${3:-}
pid=$$
message=${4:-}
latest_commit_full=$(current_commit_full)
latest_commit_short=$(current_commit_short)
latest_commit_subject=$(current_commit_subject)
EOF
}

STARTED_AT="$(timestamp)"
write_status "running" "$STARTED_AT" "" "Deployment started."

{
  echo ""
  echo "[$(timestamp)] Starting control app deployment for branch [$BRANCH]"
  cd "$REPO_PATH"
  git fetch --all --prune
  git checkout "$BRANCH"
  git pull --ff-only origin "$BRANCH"
  write_status "running" "$STARTED_AT" "" "Latest code fetched. Rebuilding control app."
  docker compose -f "$COMPOSE_FILE" up --build -d
  docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force
  docker compose -f "$COMPOSE_FILE" exec -T app php artisan optimize:clear
  docker compose -f "$COMPOSE_FILE" exec -T app php artisan optimize
  echo "[$(timestamp)] Control app deployment completed successfully."
} >> "$LOG_FILE" 2>&1 && {
  write_status "succeeded" "$STARTED_AT" "$(timestamp)" "Deployment completed successfully."
  exit 0
}

write_status "failed" "$STARTED_AT" "$(timestamp)" "Deployment failed. Check the deploy log."
exit 1
