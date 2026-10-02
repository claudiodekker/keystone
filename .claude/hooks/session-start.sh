#!/bin/bash
set -euo pipefail

# Cloud sessions start without dependencies or enabled marketplace plugins.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

# The install rewrites settings.json's formatting; keep the committed file as is.
settings="$CLAUDE_PROJECT_DIR/.claude/settings.json"
saved="$(cat "$settings")"
trap 'printf "%s\n" "$saved" > "$settings"' EXIT

# Plugins come first: a failing composer install must not leave the session without its skills.
timeout 60 claude plugin marketplace add mattpocock/skills >/dev/null 2>&1 || true
timeout 60 claude plugin install mattpocock-skills@mattpocock --scope project >/dev/null 2>&1 || true
# The private skills repo needs GitHub access; say so instead of silently running without the rulebook.
timeout 60 claude plugin marketplace add claudiodekker/skills >/dev/null 2>&1 \
  && timeout 60 claude plugin install skills@claudiodekker --scope project >/dev/null 2>&1 \
  || echo "session-start: could not install skills@claudiodekker; CODING_STANDARDS.md's general rulebook is unavailable"

export COMPOSER_ALLOW_SUPERUSER=1

# The container refuses GitHub zipballs, so composer installs from source,
# and phpstan, which ships no source, from a clone of its newest 2.x tag.
# Offline, it falls back to the newest clone an earlier start left behind.
phpstan_version=$(git ls-remote --tags --refs --sort=-v:refname https://github.com/phpstan/phpstan.git '2.*' | awk -F/ '!version && $NF ~ /^[0-9.]+$/ { version = $NF } END { print version }' || true)

if [ -z "$phpstan_version" ]; then
  phpstan_version=$(ls "$HOME/.cache" 2>/dev/null | sed -n 's/^phpstan-//p' | sort -V | tail -n 1)
fi

if [ -z "$phpstan_version" ]; then
  echo "session-start: no phpstan 2.x tag found and no earlier clone to fall back to" >&2
  exit 1
fi

phpstan_clone="$HOME/.cache/phpstan-$phpstan_version"

if [ ! -d "$phpstan_clone" ]; then
  git clone --quiet --depth 1 --branch "$phpstan_version" https://github.com/phpstan/phpstan.git "$phpstan_clone"
fi

composer config --global repositories.phpstan-local "{\"type\":\"path\",\"url\":\"$phpstan_clone\",\"options\":{\"symlink\":false,\"versions\":{\"phpstan/phpstan\":\"$phpstan_version\"}}}"
composer install --prefer-source --no-interaction --no-progress

npm install --no-audit --no-fund

# The session driver tests need the Redis server CI runs as a service.
redis-cli ping >/dev/null 2>&1 || redis-server --daemonize yes >/dev/null
