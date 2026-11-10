## Agent skills

### Issue tracker

GitHub Issues via `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `GLOSSARY.md` + `docs/adr/`. See `docs/agents/domain.md`.

### Efficiency

Firewatch records what every AppTest's requests cost: queries, cache calls, logs, duration and memory, labelled with the git commit. Before changing code, run `composer firewatch`; once your change is committed, run it again. Then ask the `firewatch` MCP server what your commit changed: `compare` for anything that got slower or heavier, `overview` for what is wrong now, and `detect` for known problem shapes such as `n-plus-one`. A regression or a new finding your change caused gets fixed in the same PR, and an older one gets an issue.
