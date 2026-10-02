#!/bin/bash
set -euo pipefail

# Cloud sessions start without dependencies or enabled marketplace plugins.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

# User scope: a project-scope install is bound to this directory, and project threads start in /home/user.
# The marketplaces are declared in settings.json, but only a session started inside this repository registers them.
install_plugin() {
  timeout 60 claude plugin marketplace add "$1" >/dev/null \
    && timeout 60 claude plugin install "$2" --scope user >/dev/null \
    || echo "session-start: could not install $2" >&2
}

install_plugin mattpocock/skills mattpocock-skills@mattpocock
# The private skills repo needs GitHub access.
install_plugin claudiodekker/skills skills@claudiodekker

npm install --no-audit --no-fund
