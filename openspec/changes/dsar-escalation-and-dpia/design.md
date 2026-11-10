# Design: dsar-escalation-and-dpia

## Verified state on HEAD (= origin/development, 6b0534094)

What exists vs what is missing — all claims checked against the working tree:

| Piece | State on HEAD |
|---|---|
| `lib/Service/Gdpr/DataSubjectDeadline.php` | Pure art-12(3) arithmetic only: `computeDueAt` (+1 month), `extend` (+2 months, once — caller-enforced), `isOverdue`, `daysRemaining`. No scheduling, no notification. |
| `escalationTier` | Declared as a calculated field on `dataSubjectRequest` (`data_subject_request_register.json:210`), tier boundaries resolved from the active `dsarPolicyPack.escalationTiers` via `@ref.pack` (index 0 reminder / 1 escalation / 2 breach), fail-safe on-track when no pack resolves. |
| Deadline notifications | THREE rules already declared (`:311`): `deadlineAdvanceReminder`, `deadlineEscalation`, `deadlineBreach` — all `trigger.type=calculatedChange` on `escalationTier` with `previously.ne` boundary guards, recipient `{kind: field, field: handler}`, nc-notification channel, NL+EN copy. |
| The gap | `calculatedChange` rules are evaluated ONLY by `lib/Listener/AnnotationNotificationListener.php` (needs old+new data from a real object write). `lib/BackgroundJob/ScheduledNotificationJob.php` iterates schemas but processes ONLY `trigger.type === 'scheduled'` entries (`:209`). **Nothing re-computes a time-dependent calculation for an untouched object** ⇒ the three declared rules are dead letters for exactly the proactive case they exist for. |
| Scheduled-trigger evaluator | `ScheduledFilterEvaluator` supports `withinNext`/`olderThan` duration operators — considered and rejected as the fix (below). |
| DPIA | `dpiaRequired` appears ONLY in the register JSON (property + seeds); `grep dpiaRequired lib --include=*.php` → no PHP hits. No detection engine anywhere in OR. |
| pipelinq semantics to generalise | `DeadlineTrackerService`: 7-day reminder → handler, <72 h escalation (skip if already breached), breach → `termijnOverschreden=true` + `fgGeinformeerd=true` + notify; every event recorded once (dedupe by event type). `DpiaDetectionService`: `DEFAULT_THRESHOLD=10`, `WINDOW_DAYS=30`, group = same article + scope, flag + inform FG. |
| Policy pack | `dsarPolicyPack` schema exists (escalationTiers, jurisdiction selection via the case's pack key, seeded default pack guarantees resolution). Natural home for detection config + officer recipient. |

## Decision 1 — re-materialise, don't re-declare

Two candidate mechanics for making the deadline rules fire:

1. **Rewrite the three rules as `trigger.type=scheduled` with `withinNext`/`olderThan` filters on
   `dueAt`.** Rejected: the tier boundaries would move out of the policy pack into hard-coded
   filter durations (breaking the jurisdiction-as-data design ADR-047 chose), `extendedUntil`
   handling would be re-implemented in filters, and `escalationTier` — which the UI, the
   `breachedCaseCount` aggregation, and the dossier already read — would still go stale.
2. **A temporal re-evaluation sweep** (chosen): `TemporalCalculationSweepJob` (TimedJob, hourly
   class, `IAppConfig` interval + enabled toggles per the `DsarRetentionSweepJob` convention)
   iterates schemas whose materialised calculations reference `now` (detectable from the
   calculation AST the `CalculationAnnotationValidator` already parses; cached per schema),
   selects objects in non-terminal lifecycle states, recomputes via `CalculationEvaluator`, and
   persists ONLY changed values through the normal `ObjectService` write path. The write emits
   the standard updated event with old+new data ⇒ `AnnotationNotificationListener` evaluates the
   `calculatedChange` rules ⇒ the three declared notifications dispatch, boundary-guarded
   (`previously.ne`) so each tier crossing notifies exactly once. Zero new notification
   machinery; the fix is generic clockwork any future `now`-dependent schema inherits.

Write amplification is bounded: a case is rewritten at most once per tier crossing (≤ 3 writes
over its whole life), because unchanged recomputations are skipped before the write path.

## Decision 2 — breach visibility

pipelinq's tracker set `fgGeinformeerd` and stamped the breach. OR equivalents:
- `breachedAt` (date-time, nullable) on `dataSubjectRequest`, written by the same sweep write
  that crosses into `breached` (declared as an `onSet`-style calculation guard: only when null —
  keeping ADR-031's data-over-code rule; the job carries no DSAR-specific branch).
- `deadlineBreach` recipients gain a second entry resolving the privacy-officer **group** from the
  active pack. The notification engine's recipient kinds already include `groups` and
  `expression` (`NotificationAnnotationValidator::VALID_RECIPIENT_KINDS`); the pack contributes
  the group name. Exact recipient kind (`groups` with a pack-resolved name vs `expression`) is an
  implementation detail decided against the dispatcher's resolver capabilities at build time —
  the requirement is only "pack-resolved officer recipient, declared on the register".

## Decision 3 — DPIA detection shape

`Gdpr/DpiaPatternDetectionService` (pure, unit-testable grouping/thresholding — mirrors
`DataSubjectDeadline`'s dependency-light style) + `DsarDpiaDetectionJob` (daily TimedJob):

```
run():
  pack = resolve active dsarPolicyPack; no pack or no pack.dpiaDetection → no-op (fail-safe)
  cases = DSAR cases with receivedAt >= now - pack.dpiaDetection.windowDays
  groups = group cases by pack.dpiaDetection.groupBy (default [type, scope-normalised])
  for each group with count >= pack.dpiaDetection.threshold:
    for each case in group where dpiaRequired != true:
      set dpiaRequired = true via ObjectService (audited write; audit context carries
      rule/groupKey/window/count)
```

Idempotency falls out of the `dpiaRequired != true` filter (flagged cases count toward the group
but are never re-written) — no detection-state table needed. Manual flags are never cleared by
the job (one-way ratchet; unflagging is a human decision). Officer notification is NOT dispatched
from the job: a declared `dpiaFlagged` rule on the register (updated-trigger, `dpiaRequired`
false→true boundary) covers both detection and manual flagging through one path — the exact
anti-imperative-dispatch posture gate-18 (notification-dialect) enforces.

Scope normalisation for grouping starts deliberately dumb (trim/lowercase/collapse whitespace) —
pipelinq's detector compared raw equality; anything smarter (similarity clustering) is a future
engine change, not v1.

## ADR-032 sizing note

Escalation + DPIA ship as ONE `kind: code` change: same register file, same policy pack, same
TimedJob conventions, one consumer's gap list — splitting would create two changes whose register
edits collide in `data_subject_request_register.json`. The register edits are incidental to the
code (ADR-032's "may incidentally touch declarative JSON" clause); there is no `mixed`-shape risk
because no schema-engine feature is being declared that code then consumes — the code makes
already-declared behaviour live.

## Out of scope

- An NL BSN identity-verify provider (pipelinq gap #3) — belongs to whichever app owns NL
  identity, recorded in `consume-or-dsar`, not an OR engine gap.
- Regulator auto-escalation on breach (the `RegulatorEscalateRegistry` seam exists; wiring breach
  → automatic AP dossier is a policy decision deferred to a policy-pack iteration).
- Rewriting the three existing notification rules' copy/channels — they ship as declared.
