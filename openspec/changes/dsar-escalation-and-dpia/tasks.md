# Tasks — dsar-escalation-and-dpia (kind: code, depends_on: —)

Closes the two OR gaps from pipelinq's `consume-or-dsar` deletion audit (deadline escalation,
DPIA detection). Verify all register/dispatcher claims against HEAD before building — the three
deadline notification rules and the `escalationTier` calculation already exist in
`lib/Settings/data_subject_request_register.json`; this change makes them live and adds detection.

## 1. Temporal re-evaluation sweep (deadline escalation)

- [ ] 1.1 Add `lib/BackgroundJob/TemporalCalculationSweepJob.php` (TimedJob; `IAppConfig` interval + enabled toggles per the `DsarRetentionSweepJob` convention): detect schemas with materialised calculations referencing `now` (reuse the calculation AST the `CalculationAnnotationValidator` parses; cache per schema), select objects in non-terminal lifecycle states, recompute via `CalculationEvaluator`, persist ONLY changed values through the `ObjectService` write path (so `AnnotationNotificationListener` fires the declared `calculatedChange` rules), skip everything else; register in `appinfo/info.xml`.
- [ ] 1.2 Verify end-to-end that an untouched DSAR case crossing a tier boundary dispatches the already-declared `deadlineAdvanceReminder` / `deadlineEscalation` / `deadlineBreach` rules exactly once per crossing (boundary `previously.ne` guard), and that a no-pack case stays on-track with no dispatch.

## 2. Breach stamping + officer visibility

- [ ] 2.1 Add the `breachedAt` (date-time, nullable, write-once) property to `dataSubjectRequest` in `lib/Settings/data_subject_request_register.json`, populated declaratively on the tier write that crosses into `breached` (no DSAR-specific branch in the sweep job).
- [ ] 2.2 Extend the declared `deadlineBreach` rule with a second recipient resolving the privacy-officer group from the active `dsarPolicyPack` (recipient kind chosen against the dispatcher's resolver capabilities — `groups`/`expression`); add the officer-group field to the `dsarPolicyPack` schema and the seeded default pack.

## 3. DPIA pattern detection

- [ ] 3.1 Add `lib/Service/Gdpr/DpiaPatternDetectionService.php` (dependency-light, unit-testable — `DataSubjectDeadline` style): group cases received inside the rolling window by the pack's `groupBy` characteristics (default type + normalised scope: trim/lowercase/collapse whitespace), return the groups meeting the threshold; fail-safe empty result when no pack or no `dpiaDetection` block resolves.
- [ ] 3.2 Add `lib/BackgroundJob/DsarDpiaDetectionJob.php` (daily TimedJob, same config-toggle conventions): for each triggering group, set `dpiaRequired = true` on unflagged cases via `ObjectService` with the audit context carrying rule/groupKey/window/count; idempotent (flagged cases count but are never re-written); never clears a manual flag; register in `appinfo/info.xml`.
- [ ] 3.3 Add the `dpiaDetection` block (threshold, windowDays, groupBy) to the `dsarPolicyPack` schema and the seeded default pack (threshold 10, window 30 days, group by type + scope — pipelinq parity).
- [ ] 3.4 Declare the `dpiaFlagged` notification rule on `dataSubjectRequest` (updated trigger, `dpiaRequired` false→true boundary, pack-resolved privacy-officer recipient) so detection and manual flagging share one declared dispatch path (gate-18 posture: no imperative dispatch from the job).

## 4. Tests

- [ ] 4.1 PHPUnit (CI way: php:8.3-cli + OCP stubs): sweep skips terminal states / unchanged values / `now`-free schemas and writes exactly the changed tier; breach stamp written once; detection service grouping + threshold + normalisation + fail-safe no-pack matrices; detection job idempotency (no rewrite, no re-audit) and manual-flag ratchet.
- [ ] 4.2 Playwright e2e for the two @e2e flows (reminder→breach sweep journey incl. officer notification; DPIA seed-eleven→flag→no-duplicate→no-op-without-pack journey); Newman assertions on the case payload (`escalationTier`, `breachedAt`, `dpiaRequired`) after job runs.
