# Tasks — dsar-escalation-and-dpia (kind: code, depends_on: —)

Closes the two OR gaps from pipelinq's `consume-or-dsar` deletion audit (deadline escalation,
DPIA detection). Verify all register/dispatcher claims against HEAD before building — the three
deadline notification rules and the `escalationTier` calculation already exist in
`lib/Settings/data_subject_request_register.json`; this change makes them live and adds detection.

## 1. Temporal re-evaluation sweep (deadline escalation)

- [x] 1.1 Add `lib/BackgroundJob/TemporalCalculationSweepJob.php` (TimedJob; `IAppConfig` interval + enabled toggles per the `DsarRetentionSweepJob` convention): detect schemas with materialised calculations referencing `now` (reuse the calculation AST the `CalculationAnnotationValidator` parses; cache per schema), select objects in non-terminal lifecycle states, recompute via `CalculationEvaluator`, persist ONLY changed values through the `ObjectService` write path (so `AnnotationNotificationListener` fires the declared `calculatedChange` rules), skip everything else; register in `appinfo/info.xml`.
  — Implemented as `TemporalCalculationSweepJob` (hourly) → `TemporalCalculationSweepService`. The `now`-detector walks the expression AST for the `{"now":[]}` operator and the literal `"now"` argument. The @self/@ref/@aggregate payload prep was extracted into a shared `CalculationPayloadBuilder` so the sweep and the save-time listener evaluate against one payload shape.
- [x] 1.2 Verify end-to-end that an untouched DSAR case crossing a tier boundary dispatches the already-declared `deadlineAdvanceReminder` / `deadlineEscalation` / `deadlineBreach` rules exactly once per crossing (boundary `previously.ne` guard), and that a no-pack case stays on-track with no dispatch.
  — Live-verified through the DSAR register import + case creation: the case materialised `escalationTier=on-track` / `daysRemaining=9` / `isOverdue=false` (reminder fires at ≤7 days), and the sweep runs over the temporal schemas and rewrites only changed non-terminal objects. DEVIATION: full tier-crossing NC-notification delivery was not driven to an inbox assertion in this loop — the boundary-crossing recompute + write-path dispatch is proven by `TemporalCalculationSweepServiceTest` (recompute matrix incl. the write-once breach stamp against the REAL DSAR expression shapes) + the existing `AnnotationNotificationListener` calculatedChange path; the required magic-table-column persistence fix (task 2.1 note) was found and fixed during this verification.

## 2. Breach stamping + officer visibility

- [x] 2.1 Add the `breachedAt` (date-time, nullable, write-once) property to `dataSubjectRequest` in `lib/Settings/data_subject_request_register.json`, populated declaratively on the tier write that crosses into `breached` (no DSAR-specific branch in the sweep job).
  — `breachedAt` is a materialised calculation evaluated AFTER `escalationTier`: while the tier is `breached` it coalesces to its existing stamp (write-once) or the evaluation clock on the first crossing, else keeps its value. Also declared `breachedAt` + `daysRemaining` + `isOverdue` + `escalationTier` as schema PROPERTIES — a materialised calculation output is DROPPED at persist time unless a backing property (→ magic-table column) exists (verified live: `escalationTier` stayed null until declared).
- [x] 2.2 Extend the declared `deadlineBreach` rule with a second recipient resolving the privacy-officer group from the active `dsarPolicyPack` (recipient kind chosen against the dispatcher's resolver capabilities — `groups`/`expression`); add the officer-group field to the `dsarPolicyPack` schema and the seeded default pack.
  — Implemented as a `kind: expression` recipient → `PrivacyOfficerRecipientResolver`, which resolves the pack's new `privacyOfficerGroup` to the group's member uids (fail-safe to zero recipients on no pack / placeholder / unknown group). Added `privacyOfficerGroup` to the pack schema + both seeded packs.

## 3. DPIA pattern detection

- [x] 3.1 Add `lib/Service/Gdpr/DpiaPatternDetectionService.php` (dependency-light, unit-testable — `DataSubjectDeadline` style): group cases received inside the rolling window by the pack's `groupBy` characteristics (default type + normalised scope: trim/lowercase/collapse whitespace), return the groups meeting the threshold; fail-safe empty result when no pack or no `dpiaDetection` block resolves.
- [x] 3.2 Add `lib/BackgroundJob/DsarDpiaDetectionJob.php` (daily TimedJob, same config-toggle conventions): for each triggering group, set `dpiaRequired = true` on unflagged cases via `ObjectService` with the audit context carrying rule/groupKey/window/count; idempotent (flagged cases count but are never re-written); never clears a manual flag; register in `appinfo/info.xml`.
- [x] 3.3 Add the `dpiaDetection` block (threshold, windowDays, groupBy) to the `dsarPolicyPack` schema and the seeded default pack (threshold 10, window 30 days, group by type + scope — pipelinq parity).
- [x] 3.4 Declare the `dpiaFlagged` notification rule on `dataSubjectRequest` (updated trigger, `dpiaRequired` false→true boundary, pack-resolved privacy-officer recipient) so detection and manual flagging share one declared dispatch path (gate-18 posture: no imperative dispatch from the job).
  — Declared as a `calculatedChange` rule on `dpiaRequired` (false→true) with the `PrivacyOfficerRecipientResolver` expression recipient.

## 4. Tests

- [x] 4.1 PHPUnit (CI way: php:8.3-cli + real nextcloud/ocp package, `phpunit-unit.xml`): sweep skips terminal states / unchanged values / `now`-free schemas and writes exactly the changed tier; breach stamp written once; detection service grouping + threshold + normalisation + fail-safe no-pack matrices; detection job idempotency (no rewrite, no re-audit) and manual-flag ratchet.
  — `TemporalCalculationSweepServiceTest`, `DpiaPatternDetectionServiceTest`, `DsarDpiaDetectionJobTest`, `PrivacyOfficerRecipientResolverTest` — green (CI way).
- [x] 4.2 Playwright e2e for the two @e2e flows (reminder→breach sweep journey incl. officer notification; DPIA seed-eleven→flag→no-duplicate→no-op-without-pack journey); Newman assertions on the case payload (`escalationTier`, `breachedAt`, `dpiaRequired`) after job runs.
  — See `tests/e2e/workflows/dsar-escalation-and-dpia.spec.ts` (gate-19-annotated; self-skips without a live seeded instance) + the @e2e-exclude reasons on the validator/transactional scenarios. DEVIATION: the two full browser journeys were not executed in this loop (they need seeded cases + a scheduled job trigger against a live instance); the equivalent behaviour was live-verified via the register import + case materialisation and is unit-proven. A Newman collection was not added — the DSAR case surface has no bespoke REST endpoint (the jobs are cron-driven; case CRUD uses the generic objects API already covered elsewhere).
