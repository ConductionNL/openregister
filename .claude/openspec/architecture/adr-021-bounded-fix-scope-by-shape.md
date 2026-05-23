# ADR-021: Reviewer bounded-fix scope is defined by change shape, not line count

**Status:** accepted
**Date:** 2026-04-23

## Context

The reviewer containers (Juan Claude van Damme for code, Clyde Barcode for security) run with bounded fix authority — they MAY apply small remediations in-container, commit, and push. The original rule in their CLAUDE.md:

> The fix is bounded to **1–3 lines in one file**.

This rule was an attempt to keep reviewers out of architectural territory. In practice it failed in two directions:

**1. Wrong-shaped for common security patterns.** A typical missing-authorization fix — add a `checkUserRole($uid, ['chair','secretary'])` block with try/catch — is 5–10 physical lines. Reviewers correctly declined to fix under the 3-line rule. On decidesk#45 (PR#129), Clyde flagged the same two auth stubs across **eight review cycles** from 2026-04-21 to 2026-04-23, each time declining as "exceeds 3-line bounded fix scope" or "architectural decision needed". The fix was literally mirroring a sibling method (`transitionLifecycle`) in the same class — zero new concepts, just apply the existing pattern. The 3-line limit turned a mechanical fix into architectural churn.

**2. Ambiguous under formatter changes.** Does "line" mean physical lines? Logical statements? With braces? A single prettier or phpcs run can convert a 3-line compact form into a 7-line expanded form and flip fix authority on or off. Reviewers should not be measuring code in a unit that formatters can redefine.

Meanwhile, genuine architectural work — new services, new schemas, new DI — IS well understood across the team. The category error was confusing "how much code changes" with "how much thinking changes".

A 10-line change that mirrors a sibling method is safer than a 2-line change that invents a new concept. We should scope by what the change touches, not by its size.

## Decision

Reviewer bounded-fix scope is defined by **change shape**, not line count. A fix is in-scope when ALL of these hold:

1. **The shape is one of:**
   - Modify an existing method body (guard clause, try/catch, validation, escape, swap unsafe call for safe one)
   - Add a new **private** helper method in the same class (no public API change)
   - Apply a pattern that **already exists in the same file or class, OR in a sibling controller/service of the same app** — mirror the precedent
   - Add a missing attribute / annotation / docblock tag
   - Swap an unsafe API for its safe counterpart (`md5` → `password_hash`, raw SQL → prepared statement, raw HTML → `htmlspecialchars`)
   - **Add a constructor parameter to inject a dependency that is already injected in a sibling controller/service of the same app** — strictly to enable a mechanical fix above (e.g. `IUserSession` → null-check → 401, `IGroupManager` → `isAdmin()` guard). The registration block in `Application.php` is updated at the same time.

2. **The change does NOT:**
   - Introduce a brand-new dependency that no sibling class in the same app already uses (first-use DI is an architectural choice — escalate)
   - Add a new service, class, interface, or route
   - Touch database schema or migrations
   - Change any public method signature visible to callers outside the class
   - Rewrite the file's top-level control flow

3. **Self-verify stays green.** Semgrep (security) or phpcs + covering phpunit (code) on the touched file produces 0 new findings.

The "sibling precedent" clause is explicit: **if a method in the same class OR in a sibling controller/service of the same app demonstrates the fix, the "architectural decision needed" escape hatch does NOT apply.** This is the clause that closes the #45 trap — the precedent in `transitionLifecycle` makes mirroring it mechanical, regardless of how many lines the mirror takes. The sibling-class extension closes the #73 trap — `MinutesVersionController`, `DecisionSearchController`, and `NotificationSubscriptionController` each lacked `IUserSession` and required a new constructor param to add auth guards, but `MinutesApprovalController` in the same app already injected it; mirroring that constructor shape is mechanical, not architectural. The bright line stays at **first-use DI** — a dependency no sibling class in the same app already uses is a genuine architectural choice and still escalates.

## Consequences

**Positive**
- Auth-guard mirroring is now in-scope for reviewers — the most common security-fix pattern stops escalating.
- Scope is robust under formatter changes: `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')` on one line or three lines is the same fix.
- The "architectural" label is reserved for genuine architectural work (new services, new roles, new DI) where a human really does need to decide something.
- Fewer `needs-input` escalations on recurring findings — fewer retry cycles — less pipeline capacity burned per PR.

**Negative**
- Reviewers have slightly more scope and therefore slightly more room to make wrong calls. Mitigations:
  - The self-verify gate (Semgrep / phpcs + phpunit green on the touched file) is unchanged — still a hard stop on regressions.
  - "No new DI / schema / public signature" is a bright line that protects the expensive classes of change.
  - "Pattern exists in same file/class" is conservative — it prevents invention, only permits mirroring.
- Reviewers now need to read adjacent methods in the same class to check for precedent. This is a small turn-count cost but produces strictly better fixes.

**Neutral**
- Line-count as a heuristic is abandoned. Reviewers still prefer small fixes over large ones — the shape rules make that natural without encoding a brittle number.

## Implementation

Applied to:
- `images/reviewer/CLAUDE.md` — the "Bound-fixable" row in the fix-category table + the "Warnings ARE in scope for fix" section
- `images/security/CLAUDE.md` — the "What you MAY fix in-container" and "What you MUST NOT fix" sections

Rolled out via PR [#136](https://github.com/ConductionNL/hydra/pull/136), 2026-04-23.

## References

- Observed failure: decidesk#45 security-review, 8 cycles documented in [docs/retrospectives/decidesk-44-45-phase-g.md](../../docs/retrospectives/decidesk-44-45-phase-g.md)
- Observed failure: decidesk#73 security-review, 5+ cycles 2026-04-23 — 7 WARNING gate-7 findings across `MinutesVersionController`, `DecisionSearchController`, `NotificationSubscriptionController`; each cycle declined under the "no new DI" rule even though `MinutesApprovalController` in the same app already injected the needed `IUserSession` / `IGroupManager`. Manually closed by the operator, driving the sibling-class relaxation above.
- ADR-013 (container pool) defines the reviewer personas; this ADR defines their authority surface.
- ADR-020 (gate scope-to-diff) is the adjacent Phase G work — together these two ADRs remove the two biggest classes of false-escalation observed on the pipeline.
