### Working agreements

- TDD for all code (`/mattpocock-skills:tdd`): red, green, refactor. No untested logic.
- No documentation beyond `CONTEXT.md`, ADRs, `docs/agents/` and the user docs in `docs/`. A PR that changes behaviour updates its user docs page. No docblocks that restate types, no README prose.
- `.scratch/` holds research that fed past decisions. Don't read it unless a decision (spec, ticket, ADR) lacks the specifics you need.
- Work in the `claudiodekker-skills:claudio-mode` skill.
- One PR delivers one complete, reviewable feature or fix. Never split a feature into "part 1, 2, 3" PRs; if it is too big to review, split the ticket.
- Boost MCP tools and guardrails: @docs/agents/laravel.md

## Opening PRs

Before opening a PR:

1. Run /mattpocock-skills:code-review in a subagent against the merge-base. Commit its fixes; only surface questions it can't resolve.
2. Re-run checks until green.
3. Use /mattpocock-skills:pr to write the description from the final diff, then open the PR.

If the diff changes after the PR is open, re-run /mattpocock-skills:pr to update the description.

- Answer review comments on the PR itself, in full. The thread gets at most a link.
- Open at most two PRs ahead of review. Each stacked PR is reviewed against its own base.
- Fix review feedback in the PR it was left on, even when later PRs extend that code.
- With more than one PR open, give the review and merge order in the thread.
- After a retro changes `CODING_STANDARDS.md` or the claudio-mode rulebook, re-review every open PR against it.
- Only a PR that is green and next to merge is ready for review; keep every other PR a draft. Mark a PR draft before pushing to it, and ready again once its checks pass.

## Agent skills

### Issue tracker

GitHub Issues via `gh` CLI. See `docs/agents/issue-tracker.md`.

### Triage labels

Default five canonical labels (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: root `CONTEXT.md` + `docs/adr/`. See `docs/agents/domain.md`.
