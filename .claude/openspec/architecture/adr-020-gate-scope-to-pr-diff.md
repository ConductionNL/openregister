# ADR-020 — Mechanical gates are scoped to the PR diff, not the whole repo

## Context

Hydra's 8 mechanical gates (`scripts/run-hydra-gates.sh`) were authored as repo-wide scanners: every `lib/**.php` file was checked on every pipeline run. This made pre-existing debt in unchanged files block every new PR. Concretely, decidesk#44 / #45 bounced through `code-review:fail → security-review:fail → needs-input` multiple cycles because `lib/Controller/SettingsController.php` (not touched by either PR) had two genuine findings — missing `#[AuthorizedAdminSetting]` on `load()` and missing `STATUS_UNAUTHORIZED` guard on `index()`. The reviewer cannot fix unchanged files in bounded scope, the builder will not re-enter fix mode for someone else's debt, and the applier refuses to override reviewer-fail verdicts. Result: two genuinely-clean PRs stuck in a ping-pong for days.

The reviewer's CLAUDE.md has long instructed Claude to apply the diff scope manually, but that is (a) advisory, not enforced, and (b) wastes turns on every run.

## Decision

Every mechanical gate in `scripts/run-hydra-gates.sh` must honor the `--scope-to-diff [BASE_REF]` flag. When set, the gate iterates only over files added, copied, modified, or renamed (`--diff-filter=ACMR`) between `BASE_REF` (default `origin/development`) and `HEAD`. Inherited debt in unchanged files is documented by a full-repo cleanup PR, not enforced via review blockers on unrelated work.

All four pipeline positions that invoke gates use `--scope-to-diff`:

| Position | Invocation site | Why scope-to-diff |
|---|---|---|
| Builder Rule 0b wrapper | `images/builder/entrypoint.sh` | Builder is creating the PR; the diff is its output. |
| Code reviewer pre-flight | `images/reviewer/entrypoint.sh` | Juan reviews the PR, not the base branch. |
| Code reviewer post-flight | `images/reviewer/entrypoint.sh` | Post-flight gate fails when Juan introduces debt; inherited debt is out of scope. |
| Security reviewer pre-flight | `images/security/entrypoint.sh` | Same rationale as code review. |
| Security reviewer post-flight | `images/security/entrypoint.sh` | Same. |

The applier runs no gates directly — it consumes the reviewers' verdicts, which now reflect scope-correct findings.

Base ref is overridable via the `HYDRA_GATE_BASE_REF` env var (default `origin/development`) for repos with a different mainline.

### Override: full-branch scope (`HYDRA_REVIEW_SCOPE=full`)

Diff scope is the default and the right choice for steady-state pipeline traffic. There are still legitimate cases for a one-off whole-branch sweep — onboarding a new repo, dedicated tech-debt audits, validating a long-lived branch before merge. Setting `HYDRA_REVIEW_SCOPE=full` (env var on the supervisor / `manual-review.sh` / `dev-run.sh` invocation) opts out of diff scoping for that run:

- The reviewer + security entrypoints drop `--scope-to-diff` from both pre-flight and post-flight `run-hydra-gates.sh` invocations — every gate scans the whole repo.
- Juan's and Clyde's prompts are rewritten to "FULL-BRANCH AUDIT MODE — review every file under /workspace/repo, not just the PR diff" so inherited debt becomes in-scope for fix authority.
- Composer/npm audit + Semgrep + manual OWASP review were never diff-scoped, so they keep behaving the same.

**Expected impact:** every PR run with `HYDRA_REVIEW_SCOPE=full` against a repo with backlog will fail until the backlog clears. This is by design — the override exists for audits, not for routine review. Default stays diff-scoped; the org-wide policy in this ADR is unchanged.

Future work (deferred): wire `HYDRA_REVIEW_SCOPE=full` to a per-issue `ready-for-full-audit` label so the override is opt-in per-PR rather than supervisor-wide.

Gate 4 (`composer-audit`) is skipped entirely when scope-to-diff is active and neither `composer.json` nor `composer.lock` is in the diff — dep vulnerabilities are unchanged if deps are unchanged. Gate 6 (`orphan-auth`) scopes the *defining* file by diff but keeps its caller grep repo-wide so a method newly-added in the PR is still validated against any legitimate same-file or cross-file caller.

## Consequences

**Positive**
- Existing debt in unchanged files no longer blocks PRs on unrelated features. The decidesk#44/#45 ping-pong is structurally impossible going forward.
- Builder, reviewer, and security all see the same scoped gate output — no more cycle-of-life where each position reads different baselines.
- Faster pipeline runs: scanning ~20 changed files instead of ~200+ repo files per gate.

**Negative**
- Inherited debt is genuinely invisible to the pipeline until it lands in a PR. Mitigation: a full-repo audit (scope-to-diff off) runs on the `ready-for-audit` label via `cron-audit.sh`, keeping the base-branch state observable.
- A PR that ONLY modifies a file lightly (e.g. renames it) may have gates pass on that file even if it has pre-existing debt. Acceptable — gates judge what the PR touched, not the file's full history.

**Deferred to Phase G.1**
- `composer check:strict` (phpcs, phpmd, psalm, phpstan) and `phpunit` / `npm run lint` are still full-repo. They run inside `composer`/`phpunit` which don't accept per-file scoping cleanly without per-tool argument passthrough. The same scoping story will land there next; for now, the reviewer's manual scope filter (`/tmp/pr-scope.txt`) remains the safety net.

## Verification

Smoke-test on decidesk PR #131 (feature/47/p2-motion-and-voting-core-t2) 2026-04-23:
- Full-repo scan: 2 FAIL (SettingsController in unchanged file)
- `--scope-to-diff --base origin/development`: ALL 8 GATES GREEN

The PR is now unblockable by unrelated debt without sacrificing gate coverage on the 19 files it actually changed.
