#!/usr/bin/env bash
set -Eeuo pipefail

PHP_BIN="php8.5"
BRANCH="main"
TARGET_SHA="${1:-}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="${DEPLOY_APP_PATH:-$(cd "$SCRIPT_DIR/.." && pwd)}"
DEPLOY_STAGE="preflight"
MAINTENANCE_ENABLED=0

fail() {
  echo "Deploy aborted: $1" >&2
  exit 1
}

require_absolute_executable() {
  local value="$1"
  local label="$2"

  [ -n "$value" ] || fail "$label is required"
  [[ "$value" = /* ]] || fail "$label must be an absolute path"
  [ -f "$value" ] && [ -x "$value" ] || fail "$label must point to an executable file"
}

handle_failure() {
  local exit_code=$?
  trap - EXIT

  if [ "$exit_code" -eq 0 ]; then
    return 0
  fi

  echo "Deploy failed during stage: $DEPLOY_STAGE" >&2

  if [ "$MAINTENANCE_ENABLED" = "1" ]; then
    if [ -n "${DEPLOY_PAUSE_WORKERS_HOOK:-}" ] && [ -x "${DEPLOY_PAUSE_WORKERS_HOOK:-}" ]; then
      "$DEPLOY_PAUSE_WORKERS_HOOK" || echo "CRITICAL: business workers could not be confirmed paused" >&2
    fi

    echo "Application remains in maintenance; business workers must remain paused." >&2
    echo "Manual forward-fix or database restore review is required. No automatic rollback was attempted." >&2
  fi

  exit "$exit_code"
}
trap handle_failure EXIT

cd "$APP_PATH"

echo "Starting deploy..."
echo "Current path: $(pwd)"
echo "Process user: $(id -un)"
echo "Current branch: $(git branch --show-current)"
echo "Current commit: $(git rev-parse HEAD)"
echo "Requested target SHA: $TARGET_SHA"

[[ "$TARGET_SHA" =~ ^[0-9a-fA-F]{40}$ ]] || fail "target SHA must contain exactly 40 hexadecimal characters"

if [ "$(git branch --show-current)" != "$BRANCH" ]; then
  fail "current branch is not $BRANCH"
fi

if [ -n "$(git status --short)" ]; then
  echo "Working tree changes:" >&2
  git status --short >&2
  fail "working tree is not clean"
fi

git fetch origin "$BRANCH"
git cat-file -e "${TARGET_SHA}^{commit}" 2>/dev/null || fail "target SHA does not exist after fetch"
git merge-base --is-ancestor "$TARGET_SHA" "origin/$BRANCH" || fail "target SHA is not reachable from origin/$BRANCH"

CURRENT_SHA="$(git rev-parse HEAD)"

if [ "$CURRENT_SHA" = "$TARGET_SHA" ]; then
  echo "Already up to date: $TARGET_SHA"
  exit 0
fi

git merge-base --is-ancestor "$CURRENT_SHA" "$TARGET_SHA" || fail "target SHA is not a fast-forward from current HEAD"

command -v "$PHP_BIN" >/dev/null 2>&1 || fail "$PHP_BIN is not available"
command -v node >/dev/null 2>&1 || fail "node is not available"
command -v npm >/dev/null 2>&1 || fail "npm is not available"

if [ -z "${HOME:-}" ]; then
  if [ -n "${DEPLOY_HOME:-}" ]; then
    export HOME="$DEPLOY_HOME"
  else
    RESOLVED_HOME="$(getent passwd "$(id -u)" | cut -d: -f6)"
    [ -n "$RESOLVED_HOME" ] || fail "HOME is missing and could not be resolved for the deploy user"
    export HOME="$RESOLVED_HOME"
  fi
fi

[ -d "$HOME" ] && [ -w "$HOME" ] || fail "HOME must be an existing writable directory"

if [ -z "${COMPOSER_HOME:-}" ]; then
  export COMPOSER_HOME="${DEPLOY_COMPOSER_HOME:-$HOME/.composer}"
fi

mkdir -p "$COMPOSER_HOME" || fail "COMPOSER_HOME could not be created"
[ -d "$COMPOSER_HOME" ] && [ -w "$COMPOSER_HOME" ] || fail "COMPOSER_HOME must be a writable directory"

[ -n "${DEPLOY_COMPOSER_PATH:-}" ] || fail "DEPLOY_COMPOSER_PATH is required"
[[ "$DEPLOY_COMPOSER_PATH" = /* ]] || fail "DEPLOY_COMPOSER_PATH must be an absolute path"
[ -f "$DEPLOY_COMPOSER_PATH" ] && [ -r "$DEPLOY_COMPOSER_PATH" ] || fail "DEPLOY_COMPOSER_PATH must point to a readable Composer script or PHAR"

COMPOSER_VERSION="$("$PHP_BIN" "$DEPLOY_COMPOSER_PATH" --version --no-ansi 2>&1)" \
  || fail "DEPLOY_COMPOSER_PATH is not executable by php8.5"
case "$COMPOSER_VERSION" in
  "Composer version "*) ;;
  *) fail "DEPLOY_COMPOSER_PATH did not identify a compatible Composer script or PHAR" ;;
esac

require_absolute_executable "${DEPLOY_PAUSE_WORKERS_HOOK:-}" "DEPLOY_PAUSE_WORKERS_HOOK"
require_absolute_executable "${DEPLOY_RESUME_WORKERS_HOOK:-}" "DEPLOY_RESUME_WORKERS_HOOK"

echo "PHP runtime: $($PHP_BIN -r 'echo PHP_VERSION;')"
echo "Composer runtime: $COMPOSER_VERSION"
echo "Node runtime: $(node --version)"
echo "npm runtime: $(npm --version)"
echo "HOME is configured"
echo "COMPOSER_HOME: $COMPOSER_HOME"

"$PHP_BIN" -m | grep -qi pgsql || fail "PHP pgsql extension is not available"

DEPLOY_STAGE="maintenance"
"$PHP_BIN" artisan down
MAINTENANCE_ENABLED=1

DEPLOY_STAGE="pause-business-workers"
"$DEPLOY_PAUSE_WORKERS_HOOK"

DEPLOY_STAGE="checkout"
git merge --ff-only "$TARGET_SHA"
ACTUAL_SHA="$(git rev-parse HEAD)"
[ "$ACTUAL_SHA" = "$TARGET_SHA" ] || fail "actual checkout SHA does not match requested target"
echo "Actual checked-out SHA: $ACTUAL_SHA"

DEPLOY_STAGE="composer-install"
"$PHP_BIN" "$DEPLOY_COMPOSER_PATH" install --no-dev --prefer-dist --no-interaction --optimize-autoloader

DEPLOY_STAGE="frontend-build"
npm ci
npm run build

DEPLOY_STAGE="migrations"
"$PHP_BIN" artisan migrate --force

DEPLOY_STAGE="production-seeders"
"$PHP_BIN" artisan db:seed --class=PermissionSeeder --force
"$PHP_BIN" artisan db:seed --class=GuatemalaLocationSeeder --force

DEPLOY_STAGE="cache-rebuild"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache

DEPLOY_STAGE="smoke-checks"
"$PHP_BIN" artisan about --only=environment --no-ansi
"$PHP_BIN" artisan config:show fel.route_automation_enabled --no-ansi | grep -Eq '\.\. false[[:space:]]*$' \
  || fail "FEL_ROUTE_AUTOMATION_ENABLED must remain false"
[ "$(git rev-parse HEAD)" = "$TARGET_SHA" ] || fail "final checkout SHA changed during deploy"

DEPLOY_STAGE="queue-restart-signal"
# Laravel workers evaluate this signal only after their current job returns.
# RunProductionDeployJob therefore remains alive until DeployService persists the final result.
"$PHP_BIN" artisan queue:restart

DEPLOY_STAGE="resume-business-workers"
"$DEPLOY_RESUME_WORKERS_HOOK"

DEPLOY_STAGE="application-up"
"$PHP_BIN" artisan up
MAINTENANCE_ENABLED=0

DEPLOY_STAGE="completed"
echo "Deploy completed."
echo "Final SHA: $ACTUAL_SHA"
