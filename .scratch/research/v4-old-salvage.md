# Old-v4 salvage report

Sources: v3 = `/Users/claudiodekker/Code/keystone-v3` (V3), old v4 = `/Users/claudiodekker/orca/workspaces/keystone-v4-old` (OLD), new repo = `/Users/claudiodekker/Code/keystone-v4` (NEW). Paths below are relative to those roots.

## Executive summary

1. The old v4 design core is sound and worth porting. It rests on one session state model (pending sign-in, ceremony slot, credential epoch), and a method's proof names a credential, never a user. Core owns the rate limits, security events and alerts. Emails belong to an account only once verified. Remember-me is Keystone's own cookie. ADRs 0005, 0030, 0050-0052, 0060-0064, 0015, 0070-0071 carry this.
2. It went off the rails on **test and enforcement infrastructure, not domain design**. About 10k LOC of parser-based arch/coverage/ASVS/docs checkers sit beside roughly 20k LOC of core logic: `packages/keystone/src/Testing` is 5,055 LOC and root `tests/Arch` has 88 files and 4,945 LOC. On top of that come a 43-class "sealed" list, a "swappable everything" promise and per-hook presentation assertions.
3. 57 of the 67 ADRs were written in one PR before any code (#121, `2174e53a`). The other 10 were mostly amendments and renames (0054, 0086-0088, 0090, 0117). The last build day was 34 of 39 commits of "Standards:" retro-fixes after CODING_STANDARDS landed.
4. It was never finished. The account recovery flow (the "forgot password" equivalent), account deletion/restore, the activity page, SMS and dialogs were never built (`packages/keystone/src/Http/EntryPoints/`, 41 entry points, no recovery).
5. The GitHub repo `claudiodekker/keystone` is gone. The issues are unreachable, so ~11 named decisions ADRs cite are lost ("Freeze the method SDK contract", "Tier rules for core", "Test kit surface", …), along with the v3 defect IDs (SI-9, RC-5, …).
6. ADR triage: 19 KEEP as-is, 26 KEEP reworded (mostly merges), 11 RECONSIDER, 11 DROP. Collapse the kept ones into roughly 25-30 fresh ADRs, with no amendment chains.
7. Main conflicts with NEW rules:
   - ADR 0100 (generated docs) vs the CLAUDE.md docs ban.
   - ADR 0085 (browser test) vs laravel.md "no real-browser tests".
   - ADR 0117 (no config comments) vs CODING_STANDARDS §15 (slope config headers).
   - ADR 0007 "every action public surface" vs §16 `@internal`/`@api`.
   - ADR 0009 "core never makes an HTTP request" vs normal Pest feature-test guidance.
8. Scope shift to decide consciously: old v4 made password, WebAuthn and TOTP pluggable method packages. In V3 they were core. NEW's laravel.md also points at Fortify's built-in passkeys, which is an unaddressed foundation question.
9. The glossary (61 terms) is roughly two-thirds keepable. Drop the test-infra jargon (AppTests machinery, workbench, posture, presentation assertion, sealed/swappable, guarantee).
10. Top decisions for the map: v4.0 scope, foundation (Fortify or not), package split, method SDK, extension model, test strategy, then the flows.

---

## 1. v3 at a glance

**Product.** A security-first Laravel auth package suite held to ASVS L2: "we made the strict choices for you" (V3 `CLAUDE.md:3`). Core owns every security outcome; the app owns presentation and HTTP wiring (`internal/architecture/ARCHITECTURE.md:22-27`). v2 was the behavioural oracle (`internal/NORTH_STAR.md:9`). PHP ^8.4, Laravel ^13.

**Packages** (V3 `CLAUDE.md:9-15`)

| Package | Role | Notes |
|---|---|---|
| `keystone` | Security engine: abstract controllers with `send*Response()` hooks, registry, Actions/Operations/State/Ports, AppTest bodies | ~613 src files, ~38.5k LOC. Password, WebAuthn, TOTP and recovery codes all live in core (`ARCHITECTURE.md:239-248`) |
| `keystone-blade` | Publish-don't-load adapter, `stubs/{shared,styled,plain,reference}` | ~73 controller stubs |
| `keystone-inertia` | Same, Vue. Two flavours (styled/plain) kept in sync (`CLAUDE.md:13`) | Wayfinder, inertiaui/modal |
| `keystone-dialogs` | Routed Inertia dialogs | **Archived** (`internal/keystone-dialogs/ARCHIVED.md:3`); the top-level copy is a duplicate |
| `keystone-magic-link` | Emailed-link first factor | config `link_expiration_minutes`, `per_email` |
| `keystone-oauth` | Socialite first factor plus account linking, blind-indexed `(provider, sub)` | `ReindexOAuthCredentials` command |
| `keystone-push` | Push-approval MFA, HMAC webhook, number match, poll | `PushSender` port |
| `keystone-sms` | SMS OTP MFA | `SmsGateway` port |

**Feature inventory a port must cover** (`ARCHITECTURE.md:187-289`, `keystone/config/keystone.php` (504 lines), `keystone-blade/stubs/shared/keystone.php:90-191`)

- **Methods:**
  - Password (knowledge).
  - Passkey and security key (phishing-resistant), with sign-count clone detection, the security-key-to-passkey upgrade and attestation `none` only.
  - TOTP (possession).
  - Recovery codes (break-glass).
  - SMS, push, magic link, OAuth (sign-in plus linking).
- **Surfaces:** login, multi_factor, sudo, registration, enrollment, recovery (`keystone/config/keystone.php:63-69`), plus multi_factor_enrollment.
- **Flows:**
  - Login, MFA challenge (the "Park"), and forced MFA and recovery-code enrollment.
  - Email-link registration (password or passkey).
  - Account recovery: link, then confirm, then reset.
  - Sudo: 900s, subnet-bound, strength floor, revocable.
  - Multi-email: add, verify, primary, backup, ownership conflict/takeover.
  - Sessions: list, revoke, revoke-others, 12h absolute lifetime.
  - Remember-me (framework recaller, `src/Actions/StartAuthenticatedSession.php:43`).
  - Known devices with a new-device alert.
  - Account soft-delete and `keystone:restore-user`.
  - Operator `TerminateUserSessions`.
  - Abandoned-challenge detection.
  - Activity log page, password change/remove, recovery-code regeneration, `/.well-known/change-password`.
- **Anti-automation:** per-IP, per-identifier and per-bucket limits, a circuit breaker, a 300ms timebox, non-enumerating answers.
- **Data:**
  - Tables: `user_emails`, `user_credentials`, `user_known_devices`, `user_security_events`, tripwires.
  - A users-table prep stub adds/removes columns (`keystone/stubs/migrations/0010_01_01_000000_prepare_users_table.php:18-29`).
- **Events and alerts:**
  - 43 event classes, with listeners wired by the provider (`KeystoneServiceProvider.php:243-326`).
  - The Notifier sends mail alerts.
  - `web` middleware: absolute lifetime and the recovery-codes gate.

**Pain points (cited)**

- **Publish seam lockstep.** Every hook change must land in the core abstract, the Blade stub, the Inertia stub and the app copy, or the app fatals while core stays green (`internal/NORTH_STAR.md:144-149`). 42 of 58 Inertia controllers drifted from their stubs (OLD `docs/adr/0001…md:4`).
- **State sprawl.** Four models and nine sibling classes evicting each other; two held identities in one session (OLD `docs/adr/0005…md:9`). The Park was still 299 lines.
- **Guarantees in listeners and middleware.** Rescued best-effort listeners carried the sudo grant, the audit write and the purge (OLD `docs/adr/0004…md:6`). Controller middleware dropped hardening (`internal/audits/2026-07-18-controller-audit.md` F3/F4).
- **Rate limiting.** Spread over ~60 files, and counters were cleared on success, which laundered sprays (OLD `docs/adr/0050…md:8`, `0051…md:8`).
- **Config sprawl.** 504 lines, 20 top-level keys, four overlapping enrollment flags (OLD `docs/adr/0110…md:7`). The `notifications.enabled = null` silently muted every alert (OLD `0063…md:7`).
- **Docs drift.** The sealed count was stated as 8/9/10/11 across four docs; 22 of 58 recorded drifts were of this kind (OLD `docs/adr/0100…md:7`). `ARCHITECTURE.md` is itself stale: State names at `:117-119`, the Notifier contradicted at `:179` vs `:400`.
- **Test weight.** ~3,100 core tests plus 533 AppTests, with flaky parallel runs. There were 88 fixture controllers (OLD `0009…md:6`), mutation testing was retired, and the simplification audit found 5 defects no test caught (`internal/audits/2026-08-17-simplification-implementation.md:5-27`).
- **Unverified email squatting** forced an ownership contest, an "invalidated" account status and placeholder users (OLD `0015…md:7`).
- **Process churn.** The divergences register has 47 entries, many of them reversals (`internal/audits/divergences-register.md`). Publish-don't-load flip-flopped (`NORTH_STAR.md:189-190`).

## 2. Old v4 architecture

**Core concepts** (OLD `CONTEXT.md`):

- Core owns every guarantee and ships no pages or routes (`:7-9`).
- Frontend adapters publish stubs (`:11-13`).
- **Every** auth method is a method package, and a method "only proves" (`:15-17`).
- A credential type has surfaces (`:19-21`, `:51-53`).
- A Proof names a credential, never a user (`:23-25`).
- Pending sign-in (`:59-61`), ceremony slot (`:71-73`) and credential epoch (`:83-85`) make up the one state model.
- Mandates: two booleans (`:67-69`).
- Rate limiter: three limits, one `throttled` refusal (`:167-185`).
- Security event, audit trail, security alert, known device (`:119-133`).
- Entry point, response hook, page value, partial (`:47-49`, `:135-145`).
- Sealed/swappable/action/operation (`:219-233`).
- AppTests, test kit, fake credential types (`:147-201`).

**Package split** (OLD `packages/`): `keystone`, `keystone-blade`, `keystone-inertia`, and methods `keystone-password`, `keystone-totp`, `keystone-webauthn`, `keystone-magic-link`, `keystone-oauth`, `keystone-push`. No SMS, no dialogs. Monorepo with one root `composer.json` (OLD `CLAUDE.md:3`).

Size:

| Package | src LOC | tests LOC |
|---|---|---|
| core | 24.9k (incl. `src/Testing` 5.1k) | 30.2k |
| each method | 0.4-1.5k | — |
| each adapter | <0.5k | — |

Root `tests/Arch` is another 4.9k.

**Differences from v3**

| Topic | v3 | old v4 |
|---|---|---|
| Password/WebAuthn/TOTP | in core | separate method packages (ADR 0002 context) |
| Method contract | ~13 capability interfaces (`src/Contracts/Methods/*`) | one `CredentialType` with `initiate`/`verify` → `Proof`, 14 SDK members (OLD `docs/reference/methods.md`) |
| Session state | Park plus siblings | pending sign-in, ceremony slots, epoch (0005) |
| Surfaces | separate `surfaces` config map | declared by type, narrowed on the methods line (0112) |
| Guarantees | listeners on `Authenticated` | inline in actions; one recorder (0004, 0060) |
| Events | 43 classes | one `SecurityEventRecorded` plus a closed type enum (0061) |
| Remember-me | framework recaller | core cookie, first factor only (0070-0071) |
| Sudo | one proof at a strength floor | full sign-in demand (0019) |
| Email | unverified attach plus contest | verified-only (0015) |
| Recovery-code gate | `web` middleware | owed at sign-in only (0016) |
| Sealing | `final` plus a test list | boot tripwire plus a contagious arch rule (0006) |
| Tests | fixture controllers plus core HTTP tests | core never makes HTTP requests; AppTests in a workbench app only (0009) |
| Frontend | styled/plain flavours, dialogs | one flavour; partials per type copied in (0080, 0084) |
| Config | 504 lines | 11 keys, seconds, bounds table (0110, 0117) |

## 3. ADR triage (all 67)

Legend: **K** = KEEP as-is, **KR** = KEEP reworded (usually merge), **R** = RECONSIDER, **D** = DROP.

| # | Title (short) | Verdict | Reason |
|---|---|---|---|
| 0001 | Entry points in one core package | KR | Sound (security fixes ship by `composer update`, drift evidence). Drop the arch-tested inner-boundary detail. |
| 0002 | Recovery codes stay in core | K | Break-glass must not depend on a plugin. |
| 0003 | Persistence only in operations, thin ones too | R | Produced 50 operations, many one-liners, plus a parser rule. Revisit whether an Operations tier earns its place. |
| 0004 | Listeners carry no guarantees | KR | Right lesson from v3. Merge into the 0060 rewrite. |
| 0005 | One session state model | KR | Best idea in the repo. Fold in 0071's fourth origin; drop the tier/rotation minutiae. |
| 0006 | Sealed = boot tripwire + dependency rule | R | Grew to 43 classes and is contagious. The accident threat model doesn't justify it; consider `@internal` (§16) plus unbound decisions. |
| 0007 | Swaps proven structurally, parser bans `new`/`app()` | D | Parser machinery. "Every action swappable" conflicts with §16 `@api`/`@internal`. |
| 0008 | No guarantee in application-owned places | KR | Keep the principle (gates inline, secrets protected at write). Drop the arch ban on Form Request hooks; revisit vs §3. |
| 0009 | Entry points tested only through AppTests | R | Forces a workbench app for every core HTTP test; at odds with standard Testbench/Pest feature tests. |
| 0010 | ASVS ids as test tags + pinned checker | R | Tags are cheap, keep them. The closed-world checker plus exclusions file is heavy; defer. |
| 0011 | Refusals asserted by name, no mutation gate | KR | Keep "assert the named reason" and "no mutation gate". Drop the parser checker and banned-assertion list. |
| 0012 | Sign-in-and-release demands evidence | KR | Real fix: every sign-in held first, release only when cleared. Drop the sealed-list restatement. |
| 0013 | Signed-in epoch check is `web` middleware | KR | Needed. Merge with 0070 into one "session check middleware" ADR. |
| 0014 | Three rules reworded to what can be built | D | Artefact of the rule machinery. The callback-GET point moves into 0033. |
| 0015 | Address belongs to account only once verified | K | Removes v3's contest, placeholder users and invalidated status. |
| 0016 | What a sign-in owes is decided only at sign-in | K | Removes v3's recovery-code `web` gate. |
| 0017 | Sudo gates settings pages; no core confirm pages | KR | Sound simplification. State the UX cost (overviews need sudo) plainly. |
| 0018 | Named L3 list, four exclusion reasons, deviations | R | Keep the deviation content (TOTP ±1, cookie prefix, URL tokens, RS256). Drop the checker framing. |
| 0019 | Sudo demands what a sign-in would demand | K | ASVS 7.5.1; closes v3's stolen-phone hole. |
| 0020 | Refuse boot on 3 cookie flags in prod | KR | Merge with 0111 and 0115 into one boot-checks ADR. |
| 0021 | Emailed links signed with host + scheme | K | Cross-env link replay fix. |
| 0030 | Proof names a credential, never a user | KR | Keystone of the method SDK. Fold in 0062. |
| 0031 | Core encrypts every secret; hashing is the method's | K | Simple, one owner. |
| 0032 | Removed method's credentials fail closed | K | Cheap (one column), prevents silent MFA loss. |
| 0033 | Callback/webhook are transports for verify | KR | Keep. Absorb 0014's "callback is the only GET that signs in". |
| 0034 | No security rests on a forgettable declaration | K | Good SDK design rule. |
| 0040 | Refusal name never leaves the server | KR | Keep "no refusal header". The test-kit exception-handler wrapping is detail for the test-strategy decision. |
| 0041 | Core AppTests run on two fake credential types | R | Depends on whether shipped AppTests survive; the fakes-in-core-src question also stays open. |
| 0042 | Presentation assertions, no default | R | One app method per hook, hundreds of them. Likely drop with a lighter AppTest model. |
| 0043 | Templated AppTests are committed tests | D | Superseded by 0045 within the same PR (churn). |
| 0044 | Arch checker ships in core's test kit | D | Puts a 5k-LOC parser engine in core's src. Over-engineering. |
| 0045 | AppTests by hand, coverage checker as checklist | D | TDD is already in NEW `CLAUDE.md`; the coverage checker is parser machinery. |
| 0050 | One limiter, three counters, one refusal | KR | Strong fix for v3's 60-file limiter. Rewrite with 0054 names and 0055 folded in. |
| 0051 | Nothing refills a counter; no typed-input keys | K | Fixes v3's high-severity SI-1/SU-4. |
| 0052 | Core owns the delivery cap | KR | Keep. Rename (0054). |
| 0053 | Limiter swappable, AppTests its guard | R | Depends on the extension-model decision; the three-AppTests-per-entry-point rule is heavy. |
| 0054 | Rate limiter and limits renamed | D | Pure rename churn. Use the final names from the start. |
| 0055 | Count before the attempt, give back | KR | Real race fix. Fold into the 0050 rewrite. |
| 0060 | Events recorded inline by one recorder; no core listeners | KR | Keep. Drop the "parser rule bans other dispatch". |
| 0061 | Security event: closed type list, fixed fields | K | Fixes v3's typed-input leak and 44-type sprawl. |
| 0062 | Rejection may name its credential | KR | Fold into 0030. |
| 0063 | Alerts queued by recorder, no links/dedup/config | K | Fixes v3's AU-1 muting. |
| 0064 | Known device is a cookie | K | Clear, fixes v3's IP noise. |
| 0065 | Repeat points at first entry of burst | R | UI grouping for an activity page that was never built. Defer until that page. |
| 0070 | Core owns the remember-me cookie | K | Strong reasoning: the recaller needs the user's password hash. |
| 0071 | Remembered sign-in is a sign-in | K | The cookie stands for the first factor only. |
| 0072 | Remember cookie: fixed lifetime from sign-in | KR | Keep. Write it in seconds from the start (0117). |
| 0080 | Method partials copied into the application | KR | Keep. Fold in 0086-0088's final prop list. |
| 0081 | Only a show step renders a page (PRG) | K | Fixes back-to-POST bugs; works for both adapters. |
| 0082 | Refusals presented by one published handler | KR | Keep. Fold in 0090. |
| 0083 | Show hooks get a typed page value; controllers hold hooks only | KR | Keep the typed page value. Drop the parser stub rule, or make it a tiny arch test. |
| 0084 | Installer copies a tree, never overwrites, core knows no adapter | K | Replaces v3's ~1,500 LOC of install logic. |
| 0085 | Adapter tested by installing it; browser test | R | Conflicts with laravel.md "no real-browser tests" (NEW `docs/agents/laravel.md:35`). The workbench build is heavy. |
| 0086 | Partial receives initiate URL, identifier, remember | D | Incremental prop amendment. Fold into 0080. |
| 0087 | Partial also receives submit URL | D | Same. |
| 0088 | Partial also receives signed link | D | Same. |
| 0089 | Sign-in link consumed before proof | KR | Sound magic-link rule. Belongs in a magic-link ADR. |
| 0090 | Refused guest goes to sign-in | D | A bug fix, not a decision. Fold into 0082. |
| 0100 | Docs restate nothing; generated reference | D | Conflicts with NEW `CLAUDE.md` ("No documentation beyond CONTEXT.md, ADRs and docs/agents/"). |
| 0110 | Config key only when two honest apps differ | KR | Keep the principle, units in names and the bounds table. Drop the config arch test. |
| 0111 | Boot check is prod-only iff the bad value has a dev use | KR | Merge 0020, 0111 and 0115 into one ADR. |
| 0112 | Surfaces narrowed on the methods line; closes registration | K | One source of truth, fails toward less. |
| 0113 | Sessions list needs the `database` driver | K | Honest documented limit. |
| 0114 | 2FA mandate off by default; app-wide only | K | Matches §9 "flags default off". Note: recovery codes default **on**. |
| 0115 | Every cache store must persist in prod | KR | Merge into the boot-checks ADR. |
| 0116 | Method installer extends one SDK base | KR | Keep the shared base. Drop the `RacesTwoProcesses` kit part. |
| 0117 | Durations in seconds; config has no comments | R | Seconds: keep. "No comments" conflicts with NEW `CODING_STANDARDS.md` §15 (config `\|` headers use the slope). |

Tally: K 19, KR 26, R 11, D 11 (= 67).

## 4. Glossary (OLD `CONTEXT.md`, 61 terms)

**Carry over:**

- Core, Frontend adapter, Method package, Credential type, Surface, Methods list, Proof.
- Subject (method-side view of an account), Passkey, Security key, Recovery codes, Entry point, Response hook, Page value, Partial.
- Pending sign-in, Mandate, Ceremony slot, Credential epoch, Remember-me cookie, Remembered sign-in.
- Verified / Pending / Primary address, Identifier, Emailed link.
- Security event, Audit trail, Security alert, Known device, Guard refusal, Sudo, Port.
- Rate limiter with Request limit, Failed-attempt limit, Delivery limit.

**Keep but scope to their package:** Cloned key (WebAuthn), Pairing (push), Out-of-band answer (push; rename "webhook answer"?).

**Drop as jargon or infra:**

- Held user: just "the pending sign-in's user".
- Sudo in progress: internal state, not vocabulary.
- Step kind: internal enum.
- Request origin: internal value.
- Listener, Action, Operation: framework and code terms, not domain.
- Swappable, Sealed, Pluggable, Model contract, Boot refusal: extension-model mechanics; re-add only if that decision keeps them.
- AppTests, Workbench application, Method posture, Test kit, Fake credential type, Credential provider, Presentation assertion: test infrastructure, not domain.
- Guarantee, Accepted deviation: process terms; keep in the ASVS ADR only if that machinery survives.

**Style note.** The OLD glossary entries are dense, multi-rule paragraphs; the pending sign-in entry alone runs about 90 words (`CONTEXT.md:59-61`). Carry the definitions over, not the embedded rules. Rules go in ADRs.

## 5. Where it went off the rails

- **Timeline.** 133 commits, 2026-09-19 to 2026-09-25, PRs #121-#256 (`git log`). The design was front-loaded: 57 ADRs plus the glossary landed in one PR before any code (`2174e53a`, #121).
- **Infra before product.** The first ~20 PRs were machinery: arch checker engine (#123), test-layer rules and banned assertions (#125), the boot refusals and config rule (#126), the method SDK fence (#124), docs checks in lint (#133), the binding rules and structural swap proof (#127), hygiene bans (#134), the ASVS tag checker (#130), the "earn-its-place" checks (#139, #148). The first user-visible flow, the sign-in page tracer, is #163.
- **Checkers checking docs and ADRs.** Root `tests/Arch` includes `AdrStatusRule.php`, `HouseRulesRule.php`, `CitationRule.php`, `DocsSamplesRule.php`, `DocsNamesRule.php`, `DocsLinksRule.php` and `AsvsClosedWorldRule.php` (88 files, 4,945 LOC).
- **Test-kit bloat in production src.** `packages/keystone/src/Testing` is 5,055 LOC (`Arch/` parser rules, fakes, `RacesTwoProcesses`, `RecordsRefusals`), and core src ships `nikic/php-parser`-driven rules (ADR 0044).
- **ADR churn:**
  - 0054 renames vocabulary across 12 ADRs.
  - 0086 → 0087 → 0088 → 0089 amend the same partial-props list four times, once per feature PR (#166, #167, #188, #198).
  - 0043 is superseded by 0045 inside the same initial PR.
  - 0117 reverses 0110's "every leaf has a comment" and unit choices.
  - The rule "never edit an ADR's body; status line carries amended by" (OLD `CLAUDE.md:31`) forces readers through amendment chains: 0005 ← 0071; 0006 ← 0012; 0050 ← 0054, 0055; 0110 ← 0054, 0117.
- **Sealed-set creep.** The 17-class skeleton list was "accepted, no numeric cap" (ADR 0012). The final list has 43 entries (`packages/keystone/src/Sealed.php`).
- **Speculative guarantees.** Examples:
  - An arch rule that every first-party method calls the conformance checks (0044).
  - Literal-only arguments to `assertHook` so a parser can count coverage (0043, 0045).
  - Generated docs tables with staleness checks (0100).
  - A pinned ASVS JSON closed-world check (0010).
- **Standards retrofit.** CODING_STANDARDS was added late (#218), then 34 "Standards: … (follow-up to #N)" PRs on 2026-09-25 (#219-#256) retro-fixed earlier PRs. Most were naming and named-args tweaks.
- **Unfinished product.** Missing:
  - Account recovery flow: the `RecoveryReset` surface exists (`src/Methods/Surface.php:15`) but there is no entry point.
  - Account deletion/restore (`account.deleted` exists only in the enum).
  - Activity page, SMS, pruning commands.
  - Old branches `claudiodekker/check-pr-*` were left behind.
- **Lost context.** The remote `claudiodekker/keystone` no longer resolves (`gh issue list` → "Could not resolve to a Repository"), so no wayfinder:map or issues can be recovered. Named decisions cited but lost: "Freeze the method SDK contract" (5 refs), "Tier rules for core" (4), "Test kit surface" (4), "Package boundaries and the method contract", "Monorepo layout and tooling", "Feature set review", "Rate limiting and circuit breaker shape", "Audit trail and security notifications shape", "Architecture walking skeleton", "ASVS scope: the owner's open rows", "Test layers and ASVS traceability". The v3 defect IDs (SI-*, RC-*, PU-*, MF-*, AU-*) have no surviving source; grep of V3 `internal/` finds none.

## 6. OLD docs worth salvaging (as input only, not copied)

NEW allows docs only in `CONTEXT.md`, ADRs and `docs/agents/`, so these become ADR content, ticket bodies or test names.

| File | Salvage | Use as |
|---|---|---|
| `docs/methods.md` (199 lines) | Per-method behaviour specs: password (SHA-256 prehash, HIBP k-anon, context words, dummy hash), TOTP (160-bit key, 3-answer slot, ±1 window, timestep once), WebAuthn, OAuth, push, magic link, recovery codes (125 bits, keyed digest, `previous_keys`) | Acceptance criteria for method tickets |
| `docs/security-alerts.md` | Alerting/non-alerting list, recipients rule, known-device cookie details (`__Host-keystone_device`, re-keying) | Alerts ADR plus tests |
| `docs/customization.md` | Remember-me cookie spec, sealed intent, `CreateAccount` for invite-only | Remember-me ADR; invite-only ticket |
| `docs/frontend.md` (162 lines) | Hook-by-hook outcomes per entry point; email-management rules; credential removal refusal rules (`:100-104`) | Flow tickets' acceptance criteria |
| `docs/writing-a-method.md` | SDK walkthrough: initiate shapes, slot, verify, callback, webhook, what core stores | Method SDK decision ticket input |
| `docs/writing-an-adapter.md` | Installer behaviour list (`:31-40`), stubs-tree layout | Adapter/installer ticket |
| `docs/testing.md` | Kit arrangers list (held at stage, throttle spent, …) | Only if shipped AppTests survive |
| `docs/reference/asvs.md` | Accepted deviations table plus requirement → test-name map | ASVS ADR input; test-name inspiration |
| `docs/reference/routes.md` | Final route-name table | Route naming ticket |
| `docs/reference/security-events.md` | Event types and fields | Events ADR |
| `docs/reference/sealed.md` | Which decisions are security-critical, with reasons | Extension-model ticket |
| `docs/installation.md` | Warns that core's migration **drops email/password/remember_token from `users`** (`:13`) | Flag: adoption risk, needs a decision |
| `docs/reference/methods.md`, `docs/README.md` | SDK member list; index | Low value |

## 7. Open fog: candidate decision tickets (ordered by what blocks what)

1. **v4.0 scope: which v3 features port.** SMS, dialogs, activity page, account deletion/restore, abandoned-challenge detection and the v3 recovery flow were missing or unfinished in OLD. This sets everything else.
2. **Foundation: build on Fortify/Laravel passkeys or stay standalone.** NEW `docs/agents/laravel.md:63-68` notes built-in passkeys/2FA; overlapping would duplicate routes, tables and the user trait.
3. **Package split: which methods live in core.** V3 had password, WebAuthn and TOTP in core; OLD extracted all of them. This drives the SDK surface, installers and the test matrix.
4. **Repo layout and tooling.** Monorepo vs per-package repos, CI shape, min PHP/Laravel versions, whether `act` is used. It blocks the first tracer.
5. **Users table ownership.** Does core strip `email`/`password` from `users` (OLD `docs/installation.md:13`) or leave the app's table alone? This is the biggest adoption risk.
6. **Session state model.** Adopt pending sign-in, ceremony slot and credential epoch (OLD 0005, 0012, 0016), including how slots persist (session vs cache) and the driver matrix (0113).
7. **Method SDK contract.** `CredentialType` initiate/verify → `Proof`, surfaces, strength, initiate shapes, webhook/callback transports (0030-0034). Freeze or iterate?
8. **Extension model.** What an app may replace: `@api` swappable actions vs `@internal` security decisions vs the sealed tripwire (0003, 0006, 0007, 0053). Must fit CODING_STANDARDS §14 and §16.
9. **Test strategy.** Core HTTP tests via Testbench vs AppTests-only (0009). Whether apps get shipped AppTests; fakes; workbench; browser tests (0085 vs laravel.md:35). How much arch testing, if any.
10. **Frontend adapter model.** Abstract entry points plus hooks, typed page values, PRG show steps, partials per type, one refusal handler, installer semantics (0080-0084). Blade + Inertia-Vue only? Styled/plain flavours?
11. **Registration and email model.** Email-first link registration, verified-only addresses, primary address, invite-only via an action (0015, 0112).
12. **Sign-in, MFA challenge, forced enrollment.** Flow and mandates (0016, 0114), including the recovery-codes-default-on choice.
13. **Account recovery flow.** Never built in OLD. What proves recovery (recovery code only, per 0002/0015?), what resets, and the epoch move.
14. **Sudo.** Full re-auth (0019), scope (all settings pages, 0017), lifetime, v3's subnet binding (dropped?).
15. **Rate limiting.** Three limits, no refill, count-before, delivery cap (0050-0055); plus the keys, windows and 429 presentation.
16. **Security events, audit trail, alerts, known devices.** 0060-0064; defer burst folding (0065) with the activity page.
17. **Remember-me.** Own cookie, first factor only, fixed lifetime, kill rules (0070-0072).
18. **Config surface and boot checks.** Key list, seconds, bounds and ceilings, prod-only rule, cache persistence, config comments (0110, 0111, 0115, 0117).
19. **ASVS traceability.** Test tags only vs checker plus exclusions file; which L3 items are claimed; the accepted deviations list (0010, 0018).
20. **User-facing docs.** NEW bans docs outside `CONTEXT.md`, ADRs and `docs/agents/`, but a package needs install and security docs eventually. Decide where and when (OLD 0100 dropped).
