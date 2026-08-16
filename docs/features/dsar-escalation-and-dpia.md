# DSAR Deadline Escalation & DPIA Pattern Detection

Two proactive GDPR-safety capabilities layered on the shipped DSAR (data-subject-request) register: the temporal deadline-escalation sweep and art-35 DPIA pattern detection. Both are cron-driven and configured entirely through the `dsarPolicyPack` (config as data, ADR-047/ADR-031); consuming apps (pipelinq, procest, zaakafhandelapp) inherit them with zero app code.

## Standards & architecture references

- GDPR art-12(3) — response deadlines; art-35 — Data Protection Impact Assessment
- ADR-047 — OpenRegister owns the AVG/DSAR workflow
- ADR-031 — Schema-declarative business logic (calculations + notification rules)
- ADR-051 §4 — OR-owned capabilities are exclusive (generic art-35 tooling belongs in OR, not a leaf)

## Deadline escalation

The DSAR register declares an `escalationTier` calculation (on-track / reminder / escalation / breached, boundaries from the active policy pack) and three `calculatedChange` notification rules (reminder, escalation, breach). Those rules only fire on an object write — so a case nobody touches never re-computes its tier and the notifications never dispatch.

`TemporalCalculationSweepJob` (hourly `TimedJob`) closes the gap generically: it detects schemas whose materialised calculations reference the evaluation clock (`now`), selects objects in non-terminal lifecycle states, recomputes via the calculation engine against the shared payload builder, and persists **only changed values** through the normal `ObjectService` write path — which emits the standard updated event with old+new data, so the declared `calculatedChange` rules fire, boundary-guarded (one dispatch per tier crossing). Any future `now`-dependent schema inherits deadline-watch behaviour for free.

Breach visibility: `breachedAt` is a write-once stamp (a calculation evaluated after `escalationTier`), and the `deadlineBreach` rule reaches both the case handler and a **privacy-officer group** resolved from the pack (`privacyOfficerGroup`, via `PrivacyOfficerRecipientResolver`). `daysRemaining` / `isOverdue` / `escalationTier` are declared as schema properties so their materialised values persist to magic-table columns.

Operator toggles: `temporal_calculation_sweep_enabled` (default true), `temporal_calculation_sweep_interval` (default 3600 s, min 300).

## DPIA pattern detection

`DsarDpiaDetectionJob` (daily `TimedJob`) partitions DSAR cases by jurisdiction, resolves each partition's active pack, and feeds the pack's `dpiaDetection` block (threshold, window, grouping fields) into the pure `DpiaPatternDetectionService`. That service groups cases received inside the rolling window by the configured characteristics (default request `type` + normalised scope) and reports every group reaching the threshold. The job sets `dpiaRequired = true` on each **unflagged** member through an audited write (`dpia.detected`, recording rule / group key / window / count).

- **Idempotent** — already-flagged cases count toward their group but are never re-written or re-audited.
- **One-way ratchet** — a manually-set flag is never cleared by the job.
- **No imperative dispatch** — the declared `dpiaFlagged` notification rule fires on the `dpiaRequired` false→true write, so detection and manual flagging share one path (gate-18 posture). The officer recipient resolves from the pack.
- **Fail-safe** — no resolvable pack, or a pack with no `dpiaDetection` block, is a no-op (no false DPIA flags).

Defaults (seeded default pack, pipelinq parity): threshold 10, window 30 days, group by type + scope. Operator toggle: `dsar_dpia_detection_enabled` (default true).

## Key capabilities

- Generic temporal re-materialisation of `now`-dependent calculations (deadline clockwork for any schema)
- Declared reminder / escalation / breach notifications made live without object writes
- Write-once `breachedAt` breach stamp + privacy-officer breach visibility (both declared, not hard-coded)
- Policy-pack-driven art-35 DPIA pattern detection with audited flagging and idempotent, ratchet-safe re-runs
- `ImportDsarRegisters` repair step imports/upgrades the DSAR case + policy-pack registers from `lib/Settings` (ADR-037)
