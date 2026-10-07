### Working agreements

- Build test-first (`/mattpocock-skills:tdd`): write the failing feature test that drives the real entry point (a request, a command, a job), then the code. Add a unit test only to pin behaviour that must never change, or to cover complex internal code that many public paths share.
- No documentation beyond `GLOSSARY.md`, ADRs, `docs/agents/` and the user docs in `docs/`. A PR that changes behaviour updates its user docs page. No docblocks that restate types, no README prose.
- `.scratch/` holds research that fed past decisions. Don't read it unless a decision (spec, ticket, ADR) lacks the specifics you need.
- Guardrails: @docs/agents/laravel.md

## Opening PRs

- Re-run checks until green before opening a PR.
- Answer review comments on the PR itself, in full. The thread gets at most a link.
- Open at most two PRs ahead of review. Each stacked PR is reviewed against its own base.
- Fix review feedback in the PR it was left on, even when later PRs extend that code.
- With more than one PR open, give the review and merge order in the thread.
- After `CODING_STANDARDS.md` changes, re-review every open PR against it.

## Agent skills

### Issue tracker

GitHub Issues via `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `GLOSSARY.md` + `docs/adr/`. See `docs/agents/domain.md`.
