---
kind: code
depends_on: []
---

## Why

When pipelinq retired its parallel AVG stack onto OR's Gdpr subsystem (ADR-047 Phase 3, pipelinq
change `consume-or-dsar`), its deletion audit mapped 15 app services onto OR replacements and found
exactly two capabilities OR does not provide (`pipelinq/openspec/changes/consume-or-dsar/design.md`
§OR-side gaps):

1. **Deadline-escalation notifications.** pipelinq's `DeadlineTrackerService` +
   `AvgDeadlineTrackerJob` proactively notified handlers (7-day reminder, <72 h escalation, breach
   + FG informed) as `dueAt` approached/passed. OR HEAD has the *declarations* but not the
   *clockwork*: `lib/Settings/data_subject_request_register.json` already declares an
   `escalationTier` calculated field (tier boundaries from the active `dsarPolicyPack` via
   `@ref.pack`, fail-safe on-track when no pack resolves) and three `x-openregister-notifications`
   rules (`deadlineAdvanceReminder` / `deadlineEscalation` / `deadlineBreach`) on
   `calculatedChange` of that field — but `calculatedChange` triggers fire ONLY from
   `AnnotationNotificationListener` on object writes, and `ScheduledNotificationJob` processes
   ONLY `trigger.type=scheduled` entries. A DSAR case nobody touches never re-computes its tier,
   so the declared reminder/escalation/breach notifications never dispatch. `Gdpr/DataSubjectDeadline`
   is pure arithmetic (computeDueAt/extend/isOverdue/daysRemaining) — nothing schedules it.
2. **DPIA pattern detection.** OR carries only the `dpiaRequired` boolean on the
   `dataSubjectRequest` schema (verified: the string appears nowhere in `lib/` PHP — register JSON
   only). pipelinq's `DpiaDetectionService` + `AvgDpiaPatternDetectionJob` flagged DPIA review when
   ≥ N similar requests (same article + scope) arrived inside a rolling 30-day window
   (`DEFAULT_THRESHOLD = 10`, `WINDOW_DAYS = 30`) and informed the FG. That heuristic is generic
   GDPR art-35 tooling, not pipelinq domain — per ADR-047/ADR-051 §4 it belongs in OR, not in a
   leaf.

Both gaps block nothing in the pipelinq migration (the app code is deleted regardless) but leave
every ADR-047 consumer without proactive Art-12 deadline safety or Art-35 pattern awareness until
OR closes them.

## What Changes

- **Temporal re-evaluation sweep** — a `TimedJob` that periodically re-materialises
  time-dependent calculated fields (calculations whose expressions reference `now`, starting with
  `escalationTier`) for objects in non-terminal lifecycle states, writing through the normal
  object write path so the already-declared `calculatedChange` notification rules fire with
  old/new data. No new notification machinery: the three declared DSAR rules become live, and any
  future schema with a `now`-dependent calculation + `calculatedChange` rule gets deadline-watch
  behaviour for free (ADR-031: behaviour stays declared on the schema; the job is generic
  clockwork).
- **Breach visibility beyond the handler** — the `deadlineBreach` rule today notifies only the
  case handler; pipelinq's tracker also informed the FG (`fgGeinformeerd`). The breach rule gains
  a second recipient resolved from the active `dsarPolicyPack` (privacy-officer group), and the
  breach crossing is stamped on the case (`breachedAt`) through the same write, so the existing
  `breachedCaseCount` aggregation and regulator dossier reflect it.
- **DPIA pattern detection** — a `Gdpr/DpiaPatternDetectionService` + daily `TimedJob`
  generalising pipelinq's heuristic: group open/recent DSAR cases by configurable characteristics
  (default: `type` + normalised scope) over a rolling window; when a group reaches the threshold,
  set `dpiaRequired = true` on the group's cases through the normal write path (audited), and
  notify the privacy officer via a new declared `x-openregister-notifications` rule on the
  `dpiaRequired` false→true transition. Threshold, window, grouping fields, and the
  privacy-officer recipient come from the `dsarPolicyPack` (new `dpiaDetection` block) — config as
  data, fail-safe: no resolvable pack ⇒ no flagging, never a false DPIA.

Register edits (breach recipient, `dpiaFlagged` rule, `breachedAt` field, policy-pack
`dpiaDetection` + officer-group fields) are incidental to the two code capabilities — centre of
mass is code (ADR-032 `kind: code`), and the two capabilities ship together because they close one
consumer's audit (same register, same policy pack, same sweep-job pattern).

## Capabilities

### New Capabilities
- `dsar-deadline-escalation`: the temporal re-evaluation sweep that makes the declared
  reminder/escalation/breach notifications dispatch without object writes, plus breach
  stamping and privacy-officer breach visibility.
- `dsar-dpia-detection`: policy-pack-driven DPIA pattern detection over DSAR cases with audited
  flagging and privacy-officer notification.

### Modified Capabilities
<!-- None. gdpr-data-subject-rights (deadline maths) and the notification engine are consumed
     unchanged; the register's declared rules gain recipients/rules but no engine requirement
     changes. -->

## Impact

- **New code**: `lib/BackgroundJob/TemporalCalculationSweepJob.php` (generic, schema-driven),
  `lib/Service/Gdpr/DpiaPatternDetectionService.php` + `lib/BackgroundJob/DsarDpiaDetectionJob.php`,
  registration in `appinfo/info.xml`.
- **Register edits** (`lib/Settings/data_subject_request_register.json`): `breachedAt` property,
  privacy-officer recipient on `deadlineBreach`, new `dpiaFlagged` notification rule,
  `dsarPolicyPack` gains `dpiaDetection` (threshold, windowDays, groupBy) + privacy-officer group;
  seeded default pack updated so a pack always resolves (existing fail-safe convention).
- **Consumes (unchanged)**: `CalculationEvaluator` (exposes `now`), the materialised-calculation
  write path, `AnnotationNotificationDispatcher` `calculatedChange` boundary-crossing semantics
  (fires once per tier crossing — natural dedupe), `ObjectService` (RBAC + audit),
  `DsarRetentionSweepJob`/`AvgRetentionJob` TimedJob conventions, `dsarPolicyPack` resolution.
- **No new endpoints, no new schemas, no lifecycle changes.**
- **Downstream**: pipelinq `consume-or-dsar` records these as its two OR deltas; zaakafhandelapp /
  procest DSAR surfaces inherit both behaviours with zero app code.
