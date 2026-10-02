#!/usr/bin/env bash
set -euo pipefail

# Playwright lives outside the repo so verification adds no dependency to the monorepo.
cache="$HOME/.cache/keystone-verify"
mkdir -p "$cache"
cd "$cache"
[ -f package.json ] || echo '{"private":true,"type":"module"}' > package.json
npm install --no-audit --no-fund playwright
npx playwright install chromium
