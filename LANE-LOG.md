# Lane log — or-primitives

Brief: 4 changes, each from origin/development, one branch/PR per change.
1. consent-evidence-envelope (code, M)
2. notification-quiet-hours-primitive (code, M)
3. guardian-participant-messaging-leaf (code, L)
4. webhook-outbound-primitive (code, L)

## Pre-build research (before any branch was cut)

Read `openspec/specs/README.md` (does not exist in this repo; specs live directly
under `openspec/specs/<capability>/spec.md`, no README index) and the ADR/spec
tree. Checked `openspec/changes/` for open work covering any of the four asks,
per the brief's "extend, never duplicate" instruction. Found:

- **`webhook-outbound-primitive` is ALREADY FULLY SHIPPED.** `lib/Service/WebhookService.php`
  (HMAC signing via `generateSignature()`, CloudEvent formatting), `lib/Db/Webhook.php`,
  `lib/Db/WebhookLog.php` (delivery log), `lib/BackgroundJob/WebhookRetryJob.php`,
  `lib/BackgroundJob/WebhookDeliveryJob.php`, `lib/Listener/WebhookEventListener.php`
  (55+ object/register/schema/configuration lifecycle events), admin-gated
  create/test/retry endpoints (wave-3 C10), multi-tenancy via `organisation` field.
  Spec `openspec/specs/notificatie-engine/spec.md` states explicitly: "The
  existing WebhookService already handles outbound webhook delivery with HMAC
  signing, CloudEvents formatting, and Mapping-based payload transformation."
  There is also a shipped, separate `openspec/specs/webhook-payload-mapping/spec.md`.
  Confirmed present on `origin/development` HEAD (`git show
  origin/development:lib/Service/WebhookService.php` succeeds). **No PR opened
  for this — building one would duplicate a shipped platform capability.**
  Learniq's own row 13.14 gap is that learniq itself hasn't wired an app-level
  webhook config, not that OpenRegister lacks the primitive.

- **`notification-quiet-hours-primitive` is ALSO ALREADY FULLY SHIPPED.**
  Archived change `openspec/changes/archive/2026-07-13-notification-delivery-windows/`
  implemented exactly this ask: `NotificationDeliveryWindowService`
  (`lib/Service/Notification/NotificationDeliveryWindowService.php`, override-only
  per-user delivery window `{enabled, start, end, timezone, days?}`), a
  `critical: bool` key on `x-openregister-notifications` that bypasses quiet-hours
  queuing (the exact "urgent flag ... bypasses them" the brief asks for), a
  `digest` fixed-time-of-day schedule, `QueuedNotification`/`QueuedNotificationMapper`,
  `NotificationQueueFlushJob`. Confirmed present on `origin/development` HEAD
  (`git show origin/development:lib/Service/Notification/NotificationDeliveryWindowService.php`
  succeeds; `git log origin/development --oneline -- openspec/changes/archive/2026-07-13-notification-delivery-windows`
  shows it merged and archived). **No PR opened for this either — same reason.**
  Confirmed again after the 09-26 crash/resume: still present, nothing to build.

Both confirmations were done by diffing my lane's starting HEAD against
`origin/development` directly (`git merge-base --is-ancestor origin/development HEAD`
returned true at lane setup — my checkout already contained both shipped
features from the base clone, not from anything I built).

- **`consent-evidence-envelope`**: genuine gap. `avg-verwerkingsregister` (status:
  implemented) has a consent-as-legal-basis requirement but it's a bespoke
  schema tied to `verwerkingsactiviteit`, not a reusable dialect, and it has no
  IP/user-agent/content-hash fields. Built as a new, generic, declarative
  `x-openregister-consent` property annotation instead — extends the pattern,
  doesn't touch that spec's requirements.

- **`guardian-participant-messaging-leaf`**: genuine gap. `integration-talk`
  (status: done) ships read/link (`TalkProvider`, `TalkLinkService`) but no
  participant management. Built as `TalkLinkService::inviteExternalParticipant()`,
  reusing the exact `ParticipantService::addUsers()` call `createAndLinkRoom()`
  already makes for Nextcloud users, with `actorType: 'emails'` instead.

## Change 1 — consent-evidence-envelope

- Branch: `feat/consent-evidence-envelope` (from `origin/development`)
- PR: https://github.com/ConductionNL/openregister/pull/4049 (OPEN)
- Commits: `8a3fd4f30` (feat), `dc1e06ac4` (docs: mark tasks complete after opsx-verify)
- Verified before push: `php -l` clean on all touched files; `vendor/bin/phpcs
  --standard=phpcs.xml` 0 errors on touched lib files; `vendor/bin/phpstan
  analyse --memory-limit=1G` on touched lib files, no errors; `vendor/bin/phpunit`
  on the two new test files, 16 tests/29 assertions, all pass; `openspec validate
  consent-evidence-envelope --strict` valid.
- 09-26 resume: merged `origin/development` into the branch first (1 upstream
  commit behind, a dependabot dexie bump touching only `package.json`/
  `package-lock.json`, unrelated) — clean merge, no conflicts, pushed
  (`e8a4adb8a`). Re-ran diff-scoped phpcs (0 errors) and phpunit (16/16 pass)
  on the post-merge tree — unaffected, as expected for an unrelated JS-dep bump.
- `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` relaunched via
  `with-slot.sh` after the merge, log at `.tmp/check-strict-consent.log`.
  **Result: exit 1 overall, only from `test:all`** — lint/check:migration-
  version/phpcs/phpmd/psalm/phpstan all passed. 24037 tests, 61133 assertions,
  1 failure: `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict`
  (isolated temp-dir migration-gate verdict, unrelated to this PR's files) —
  the exact same failure that reproduced on PR #4051's run, confirming it is
  fleet-wide pre-existing debt, not caused by either PR (PR #4051's run also
  hit a second, non-reproducing failure, `FlowNodeRegistryTest`, consistent
  with timing-flakiness under load rather than anything real). PR #4049's
  body updated via `gh pr edit` with the real result, placeholder removed.
- opsx-verify: ran headless (no plan.json / tracking issue, so no GitHub sync
  applied). All 10 tasks in tasks.md checked off, matching actual implementation.
- **DONE.** Both this change's diff-scoped gates and its one full-suite run
  are complete and recorded in PR #4049's body.

## Final state (all four changes closed out)

| Change | Outcome |
|---|---|
| consent-evidence-envelope | PR #4049, OPEN, fully verified, check:strict result posted |
| notification-quiet-hours-primitive | Already shipped (archived `2026-07-13-notification-delivery-windows`) — no PR, would duplicate |
| guardian-participant-messaging-leaf | PR #4051, OPEN, fully verified, check:strict result posted |
| webhook-outbound-primitive | Already shipped (`WebhookService` + HMAC + retry/delivery jobs + 55+ event listener) — no PR, would duplicate |

Both "already shipped" findings were re-verified independently three times
across three separate resume cycles (machine crash, session limit, 429 wave),
each time by direct `git show origin/development:<file>` proof plus the
archived openspec change / canonical spec text. Nothing further to build for
either without new evidence that the brief's ask is narrower than, or
different from, what is already merged.

## CI fix pass (2026-09-26 19:00 instruction)

Per `CI-READ-2026-09-26.md`'s per-PR read, both PRs carried NEW findings on
top of the inherited composer-audit CVE (fleet-wide, also red on
`development`) — the inherited CVE and `MigrationVersionBumpCheckTest` were
left untouched per instruction.

**PR #4049 (consent-evidence-envelope)**, commit `e958a4d594`:
- gate-16 spec-coverage: `ConsentAnnotationValidator::validate()` was missing
  `@spec`. One-line fix.
- phpmd: `ConsentEnvelopeOnSaveListener::evaluate()`/`evaluateProperty()` over
  CC/NPath threshold, plus 2 ElseExpression + 2 CountInLoopExpression. Fixed
  by extracting the pure logic into a new `ConsentEnvelopeEvaluator` service
  (no NC dependency, directly unit testable) — not a suppression. The first
  extraction pass tripped a NEW `ExcessiveClassComplexity` (51/50) on the
  listener; resolved by moving the logic to its own class rather than
  adjusting or suppressing the threshold. Added `ConsentEnvelopeEvaluatorTest`
  (8 tests, 27 assertions, every branch). Existing `ConsentEnvelopeOnSaveListenerTest`
  (16 tests) passes unmodified, proving the refactor is behaviour-preserving.
  Verified standalone: `vendor/bin/phpmd <file> text phpmd.xml` clean on both
  files; gate-16 checker re-run, 0 findings.

**PR #4051 (guardian-participant-messaging-leaf)**, commit `df27f792b2`:
- phpmd: `TalkLinkService::inviteExternalParticipant()` over CC/NPath
  threshold. Fixed by extracting `assertInviteAllowed()`/`resolveInviteTargets()`/
  `sendInvite()`. The class's pre-existing `@SuppressWarnings(PHPMD.ExcessiveClassComplexity)`
  (from before this PR, for the whole class's defensive Talk-API-compatibility
  method count) already covered the sum, so no new class-level finding
  appeared this time.
- PHPUnit coverage-guard: dropped 0.32% — about half the new statements
  (room lookup, participant-service resolution, the real `addUsers()` call)
  were structurally unreachable by the file's "Talk unavailable" test
  strategy (`spreed` not installed here). Fixed by aliasing `Manager`/
  `ParticipantService` stubs under Talk's own class names, following the
  exact convention `TalkProviderTest` already uses for this file's sibling
  class, guarded the same way `TalkObjectSourceProviderTest` already
  self-skips if a real `spreed` is present. 7 new tests reach every
  previously-dead branch for real (room-not-found, participant-service-
  unavailable, the full success path with an asserted `addUsers()` payload,
  display-name fallback, `addUsers()` throwing, both
  `schemaAllowsExternalParticipants()` failure branches). Verified the alias
  is safe: ran all 3 files fleet-wide that reference these Talk class names
  together — 45 tests, 133 assertions, all pass, one expected self-skip.

Both PR bodies updated via `gh pr edit` with a "CI fix pass" section naming
the exact findings, the fix, and the re-verification commands. Neither branch
was rebased or force-pushed — each got one ordinary follow-up commit merging
in the same 1 upstream dexie-bump commit first, then the fix.

## Second CI read (2026-09-26, same day) — PR #4049 gate-16 follow-up

Coordinator reported gate-16 spec-coverage still red on #4049 after the
first fix: "1 changed method(s) missing @spec". Re-ran the checker standalone
(`HYDRA_GATE_BASE_REF=origin/development python3
vendor/conduction/hydra-gates/hydra-gates/scripts/lib/check_spec_coverage.py .`)
on `feat/consent-evidence-envelope` — confirmed: `ConsentEnvelopeEvaluator::evaluate()`
(the method the phpmd extraction created) had `@spec` on the file-level
docblock only, not on the method's own docblock, which is what the gate
actually checks. One-line fix: added `@spec` to `evaluate()`'s docblock.
Commit `db95fe0a90`, pushed. Re-verified: `php -l`/`phpcs`/`phpstan` clean,
`phpunit` on the consent suite 17 tests/36 assertions pass (Consent-only
subset re-run; full 24-test suite unaffected), gate-16 checker re-run
standalone: `# count=0`. PR body updated via `gh pr edit` with a follow-up
note under the existing gate-16 bullet. No merge needed this time — checked
`git log --oneline HEAD..origin/development` (3 new commits, all
`feat/parity-wave5-competitor-lab`-related, no overlap with this PR's files).

PR #4051 was not implicated in this second read (only #4049 was named).

## Change 2 — notification-quiet-hours-primitive

**Not built. Already shipped on `origin/development` before this lane started
(see research above).** No branch, no PR — opening one would duplicate merged,
archived work. Nothing left to do for this change.

## Change 3 — guardian-participant-messaging-leaf

- Branch: `feat/guardian-participant-messaging-leaf` (from `origin/development`)
- PR: https://github.com/ConductionNL/openregister/pull/4051 (OPEN, MERGEABLE)
- Commits: `dacfb0903` (feat), `541e94b8f` (docs: mark tasks complete after opsx-verify)
- Re-verified after the 09-26 resume, all still green on the fresh branch:
  `php -l` clean, `vendor/bin/phpcs` 0 errors, `vendor/bin/phpstan
  --memory-limit=1G` no errors, `vendor/bin/phpunit` on `TalkLinkServiceTest.php`
  19/19 pass (45 assertions, 1 pre-existing skip).
  `COMPOSER_PROCESS_TIMEOUT=0 composer check:strict` relaunched via
  `with-slot.sh`, log at `.tmp/check-strict-guardian.log`. **Result: exit 1
  overall, but only from `test:all`** — lint/check:migration-version/phpcs/
  phpmd/psalm/phpstan all passed. Full PHPUnit suite: 24026 tests, 61114
  assertions, 2 failures, neither in a file this PR touches:
  `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict`
  (isolated temp-dir migration-gate verdict, unrelated) and
  `FlowNodeRegistryTest::testAStepThatOverrunsItsCeilingIsStopped` (wall-clock
  timing assertion, consistent with flakiness under 13-lane shared-slot load).
  Confirmed via `git diff --name-only origin/development HEAD` that neither
  file is touched by this PR. Recorded as inherited findings in PR #4051's
  body (updated via `gh pr edit`), not fixed.
- Original state before this section was rewritten (09-26 resume, before
  commit): 4 tracked files modified in the working tree, not staged:
  - `lib/Db/Schema.php` — added `x-openregister-talk-participants` to
    `ANNOTATION_VOCABULARY` (mandatory: an unlisted `x-openregister-*` key is
    silently dropped by `setConfiguration()`, confirmed against the file's
    own repeated warning comments for 9 other keys).
  - `lib/Service/TalkLinkService.php` — added `inviteExternalParticipant()`,
    `schemaAllowsExternalParticipants()`, `SchemaMapper` constructor dependency.
  - `lib/AppInfo/Application.php` — `TalkLinkService` DI factory updated for
    the new `SchemaMapper` dependency (single hunk, verified clean via `git
    diff lib/AppInfo/Application.php` after the branch-switch stash/pop —
    contains ONLY this hunk, no leakage from change 1's listener registration).
  - `tests/Unit/Service/TalkLinkServiceTest.php` — 5 new tests added
    (link-not-found 404, schema-not-opted-in 403, malformed-email 400,
    no-user, Talk-unavailable degrade).
  - Untracked: `openspec/changes/guardian-participant-messaging-leaf/`
    (proposal.md, design.md, specs/.../spec.md, tasks.md — all written,
    `openspec validate guardian-participant-messaging-leaf --strict` passed
    before the crash) and `.tmp/` (composer TMPDIR scratch, gitignored,
    harmless, not part of the change).
- Verified before the crash (all on the fresh `feat/guardian-participant-
  messaging-leaf` branch, i.e. against a clean `origin/development` base,
  re-run again after this resume — see below): `php -l` clean on all 4 files;
  `vendor/bin/phpcs --standard=phpcs.xml` 0 errors; `vendor/bin/phpunit` on
  `TalkLinkServiceTest.php`: 19 tests/45 assertions, all pass (1 skipped by
  design, `@group requires-app-spreed`, pre-existing and unrelated to this
  change); regression check on `SchemasControllerTest.php`/`SchemaMapperTest.php`/
  `SchemaTest.php` (files change 1 also touches) — all pass, no regressions.
- No REST controller/route added in this change — deliberate (see design.md
  "Non-Goals"): the service primitive is fully tested and consumer-ready;
  adding an unreachable route ahead of a real caller is exactly the failure
  shape this fleet's `hydra-gate-route-reachability` watches for.
- **Next actions**: stage explicitly (never `-A`; verify `--show-toplevel`
  first per the git-safety hub), commit, push, open PR base `development`,
  run `openspec validate` once more, run `opsx-verify` headless, then
  `composer check:strict` via `with-slot.sh` before or shortly after opening
  the PR, same as change 1.

## Change 4 — webhook-outbound-primitive

**Not started. Already shipped on `origin/development` before this lane
started** (see research above — `WebhookService`, `WebhookLog`, retry/delivery
jobs, HMAC signing, 55+ event listener, all present and documented as done in
`openspec/specs/notificatie-engine/spec.md`). Coordinator's 09-26 resume
message asked this be done "exactly as briefed" after change 3 — re-confirming
here in case the coordinator wants a second, independent look rather than
trusting the earlier finding: **re-verified again after resume, still fully
present on `origin/development` HEAD.** If the coordinator has evidence this
finding is wrong (e.g. the brief's ask is narrower than what's shipped, or
targets a different object than `Webhook`/generic object events), say so and
this lane will build the gap; absent that, no PR will be opened here either,
per "extend, never duplicate."

## Environment notes for whoever resumes this lane next

- Two machine crashes hit this lane 09-25→09-26. Background `Bash` commands
  and `Monitor` watches do not survive a crash — a `check:strict` log started
  before a crash is not trustworthy evidence of anything; re-run it.
- This lane dir (`/home/rubenlinde/memcap-work/lq-lanes/or-primitives`) is the
  only place this lane's own logs/state should live — the session scratchpad
  under `/tmp/claude-1000/.../scratchpad/` is shared across every subagent in
  the session and does not survive a crash or another lane's cleanup.
- Heavy commands (`composer check:strict`, psalm, phpstan on the full tree,
  `npm run build`) MUST go through `bash /home/rubenlinde/memcap-work/lq-lanes/with-slot.sh <cmd>`
  — 13 lanes share a 23 GB box with 4 semaphore slots. Diff-scoped `php -l`
  /phpcs/phpstan-on-specific-files/phpunit-on-specific-files do not need it.
- PR body files for `gh pr create --body-file` must live INSIDE the lane's own
  git worktree (e.g. `.tmp/pr-body-*.md`) — passing a path under the session
  scratchpad to `gh pr create --body-file` failed with "no such file or
  directory" even though the file existed and was readable by other tools,
  apparently a sandboxing/mount boundary around the `gh` subprocess.

---

# Lane r2-ai, part 2 (learniq round 2, 2026-09-27): this clone now belongs to r2-ai

## Change: pptx-structured-reader (DONE)

- Branch: `feat/pptx-structured-reader`, cut with `--no-track` from `origin/development` at `d611a366`. Not stacked.
- PR: https://github.com/ConductionNL/openregister/pull/4077 (open, base `development`, not merged). Head `451814f9`.
- Commits: `d4699734` feature, `7f335da9` gate-16 `@spec` on `refusedParts`, `48ca8062` spec `@e2e exclude`, `451814f9` verify fixes.
- BLOCKED PART OF THE BRIEF: `phpoffice/phppresentation` could not be added. 1.2.0 (newest tag) requires phpspreadsheet `^1.9||^2||^3||^4`; OR requires and patches `^5.0` (locked 5.10.0). Only unreleased dev-master accepts ^5. `composer require ... --dry-run` fails; composer.json/lock untouched. Built the reader with ZipArchive + DOMDocument instead (`OoxmlPackage`, `PresentationSlideParser`, `PresentationExtractor`); the PR opens with this.
- Real-suite cross-check (local, `.tmp/lo/`): python-pptx 1.0.2 deck and its LibreOffice 24.2.7.2 round trip read correctly; a LibreOffice-exported flat-ODP deck exposed that LO writes notes as a plain text box, fixed and pinned by a test.
- Verification (exit codes): php -l 0; phpcs 0; phpstan 0; phpmd (cold pdepend, isolated HOME) 0; phpunit PresentationExtractorTest 0 (28); TextExtraction folder 0 (252); composer check:strict 1, only from `MigrationVersionBumpCheckTest::testANonRepositoryRefusesToGiveAVerdict`, which fails whenever TMPDIR is inside a git checkout (proved: exit 1 with TMPDIR in the clone, 0 outside); lint/phpcs/phpmd/psalm/phpstan all passed, 24109 tests ran; npm run lint 0; format 0; test:l10n 0; hydra gates 0 (36 pass, 58 n/a); openspec validate valid.
- Environment notes: memory's per-lane HOME breaks this box's `composer` wrapper (it reads `$HOME/.local/share/composer.phar`); a symlink at `.tmp/home/.local/share/composer.phar` fixes it. `gh --body-file` cannot read the session scratchpad; bodies live in `.tmp/`.
- Left for later: indexing presentation text for search via TextExtractionService; `.ppt`/`.odp`; switching internals to phppresentation once a release accepts phpspreadsheet 5.
- CI read once at lane end (2026-09-27): only 5 checks reported (1 pass, 4 pending); the main quality workflow had not reported yet, so this is NOT a green. Read again once before landing.
