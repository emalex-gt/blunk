#!/usr/bin/env bash
set -Eeuo pipefail

APP_PATH="/home/kodbli-v2/htdocs/v2.kodbli.app"
PHP_BIN="php8.5"
BRANCH="main"

cd "$APP_PATH"

echo "Starting deploy..."
echo "Current path: $(pwd)"
echo "Current branch: $(git branch --show-current)"
echo "Current commit: $(git rev-parse --short HEAD)"

if [ "$(git branch --show-current)" != "$BRANCH" ]; then
  echo "Deploy aborted: current branch is not $BRANCH"
  exit 1
fi

if [ -n "$(git status --short)" ]; then
  echo "Deploy aborted: working tree is not clean"
  git status --short
  exit 1
fi

git fetch origin
echo "Target commit: $(git rev-parse --short origin/$BRANCH)"

APP_WAS_DOWN=0

restore_app() {
  exit_code=$?

  if [ "$APP_WAS_DOWN" = "1" ]; then
    echo "Attempting to bring application back up..."
    "$PHP_BIN" artisan up || true
  fi

  exit "$exit_code"
}
trap restore_app EXIT

git pull --ff-only origin "$BRANCH"

composer install --no-dev --optimize-autoloader
npm ci
npm run build

"$PHP_BIN" -v
"$PHP_BIN" -m | grep -i pgsql

"$PHP_BIN" artisan down
APP_WAS_DOWN=1

"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan db:seed --class=PermissionSeeder --force
"$PHP_BIN" artisan db:seed --class=GuatemalaLocationSeeder --force
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan queue:restart

"$PHP_BIN" artisan up
APP_WAS_DOWN=0

echo "Deploy completed."
echo "New commit: $(git rev-parse --short HEAD)"
