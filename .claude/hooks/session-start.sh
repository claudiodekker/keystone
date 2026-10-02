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
# The private skills repo is already cloned next to this one, and a local directory needs no GitHub credentials.
skills_source=claudiodekker/skills
if [ -d "$CLAUDE_PROJECT_DIR/../skills/.claude-plugin" ]; then
  skills_source="$(cd "$CLAUDE_PROJECT_DIR/../skills" && pwd)"
fi
install_plugin "$skills_source" skills@claudiodekker

npm install --no-audit --no-fund
