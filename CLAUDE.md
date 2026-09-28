### Working agreements

- TDD for all code (`/tdd`): red, green, refactor. No untested logic.
- No documentation beyond `CONTEXT.md`, ADRs, `docs/agents/` and the user docs in `docs/`. A PR that changes behaviour updates its user docs page. No docblocks that restate types, no README prose.
- `.scratch/` holds research that fed past decisions. Don't read it unless a decision (spec, ticket, ADR) lacks the specifics you need.
- Small PRs: one ticket, one tracer-bullet slice per PR.
- Laravel, Pest and Boost MCP guidance: @docs/agents/laravel.md

## Opening PRs

Before opening a PR:

1. Run /code-review in a subagent against the merge-base. Commit its fixes; only surface questions it can't resolve.
2. Re-run checks until green.
3. Use /pr to write the description from the final diff, then open the PR.

If the diff changes after the PR is open, re-run /pr to update the description.

## Agent skills

### Issue tracker

GitHub Issues via `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `CONTEXT.md` + `docs/adr/`. See `docs/agents/domain.md`.
