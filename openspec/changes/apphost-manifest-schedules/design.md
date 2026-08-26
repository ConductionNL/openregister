## Context

OpenRegister already hosts two generic AppHost engines that consume
manifest-declared capabilities on behalf of virtual apps that ship no PHP:

- **Observability** — a manifest declares `observability` (metrics, health
  checks); a generic engine (`lib/AppHost/Observability/ManifestLoader.php` +
  `MetricsEngine.php`) consumes it. The Settings/Dashboard/Health/Metrics/
  DeepLinks AppHost engines all live under `lib/AppHost/`.
- **Credentials** — a manifest declares `credentials[]`; OR brokers them.

Scheduling is the missing **temporal** peer. Verified ground truth:

- **Classic scheduling** = `OCP\BackgroundJob\TimedJob` + `appinfo/info.xml
  <background-jobs>` (`openregister/appinfo/info.xml:94-121`,
  `openconnector/appinfo/info.xml:50-54`, `openbuild/appinfo/info.xml:101-104`).
  A virtual app ships no `jobClass` and cannot add `<background-jobs>`.
- **A data-driven scheduler already exists to reuse.** OpenConnector's
  `lib/BackgroundJob/JobTask.php:63,91` is a `TimedJob` (interval 300s) that calls
  `jobService->run()`. `JobService::run()`
  (`openconnector/lib/Service/JobService.php:638-665`) iterates OR `job` objects
  (schema `job`, register "OpenConnector Register", `isEnabled=true`, skipping
  those with a future `nextRun`), resolves the action via
  `containerInterface->get($jobData['jobClass'])` (`JobService.php:397`), calls
  `$action->run($arguments)`, and advances `lastRun`/`nextRun`
  (`JobService.php:433-460`). The `job` schema (jobClass, interval, isEnabled,
  nextRun, arguments, userId) is defined in
  `openconnector/lib/Settings/openconnector_register.json`.
- **OR's native temporal template to mirror** is
  `lib/BackgroundJob/ScheduledWorkflowJob.php:70,84-90` — a 60s `TimedJob` →
  `mapper->findAllEnabled()` → engine adapter.
- **Owner impersonation for session-less jobs is solved**:
  `openbuild/lib/Service/JobOwnerImpersonator.php:40` and OpenConnector's
  `JobService.php:368-390`.
- **Virtual apps** are OR objects in the `openbuild` register `application`
  schema; their manifest is resolved by
  `openbuild/lib/Service/ManifestResolverService.php`. On-disk manifest apps are
  served by OR's AppHost directly.
- **Manifest v2 schema** (`nextcloud-vue/src/schemas/app-manifest-v2.schema.json`)
  today has: `$schema, version, openbuildEditable, dependencies, setup,
  walkthrough, nav, runtime, menu, pages, credentials, observability, deepLinks,
  pageTemplates, pageInstances, sets` — **no** scheduling key. Types live in
  `nextcloud-vue/src/types/manifest.d.ts`.

The design is a straight composition: **manifest `schedules[]` → generic OR
reconciler → OpenConnector `job` objects → existing JobTask/JobService
execution.** OR writes the reconciler and owns on-disk + virtual manifest
enumeration; the execution/logging path is reused unchanged.

## Why OpenRegister owns this (NC has no cron API)

Nextcloud exposes **no cron API beyond the raw `OCP\BackgroundJob\TimedJob` PHP
primitive** — driven by `cron.php`/system cron. There is no REST, no OCS, and no
declarative cron surface in core: the only way to get recurring work is to ship
a PHP `TimedJob` class registered in `appinfo/info.xml`. A pure-virtual app
(manifest only, no PHP on disk) therefore has *no* path to scheduling at all,
and every classic app that wants data-driven scheduling re-invents the same
loop — OpenConnector's `JobTask` and OR's own `ScheduledWorkflowJob` are two
independent hand-rolled instances of exactly this pattern.

That is why a generic, **manifest/data-driven scheduling engine belongs in
OpenRegister** — the foundation the fleet already builds on. Placing it here
means openbuild, hermiq, openconnector and any manifest app consume **one OR
scheduling engine** (declare `schedules[]`, done) instead of each hand-writing a
`TimedJob`. This change generalises the two existing per-app duplications
(`JobTask`, `ScheduledWorkflowJob`) into a single foundation capability, exactly
as OR already owns the foundation Observability and Credentials engines. It is
the temporal foundation primitive core does not provide.

## Goals / Non-Goals

**Goals:**

- Let a pure-virtual manifest app declare recurring work (`schedules[]`) and
  have it actually run, with zero PHP on disk for that app.
- Reuse OpenConnector's proven data-driven scheduler for execution — write no
  new execution/logging engine.
- Mirror `ScheduledWorkflowJob` for the reconciler `TimedJob`, and mirror the
  Observability engine's manifest-declared → generic-engine pattern exactly.
- Idempotent, GC'd reconciliation: exactly one `job` per
  `applicationId + scheduleId`; disabled/removed schedules stop running.
- Safe by construction: action allow-list (no tenant-supplied FQCN), owner
  resolved from the application (no author-supplied identity).

**Non-Goals:**

- No new execution engine, no new `nextRun`/logging path (reuse JobService).
- No new OR schema and **no seed data** — the OpenConnector `job` schema already
  exists; reconciled jobs are OC `job` objects.
- No new HTTP endpoints and no HTTP-contract change.
- No hand-rolled cron parser — reuse a vendored cron-expression library for
  cron→nextRun (see D-2).

## Decisions

### D-1 — Declarative-vs-imperative decision (ADR-031)

**The manifest `schedules[]` declaration is declarative; the reconciler is a
genuinely imperative `TimedJob` — an explicit, justified ADR-031 exception.**

ADR-031 says: when OR provides a schema extension that fits a requirement,
declare the behaviour rather than write a service. The **declaration** side of
this change honours that — a schedule is manifest metadata, the temporal peer of
manifest `observability`/`credentials`, not hand-written PHP per app. But
ADR-031's declarative extensions (`x-openregister-lifecycle/-aggregations/
-calculations/-notifications`) all express **derived-from-object-state** logic
evaluated at read/transition time. A scheduler is different in kind: it is
**scheduled bulk work on a clock**, with no triggering object mutation — exactly
the category `ScheduledWorkflowJob` already occupies in OR (a `TimedJob` that
sweeps enabled records). There is no `x-openregister-*` extension that expresses
"run this action every N seconds", and inventing one would just be a second name
for a `TimedJob`.

So: the **reconciler `TimedJob` is imperative by necessity** — it is the
*missing temporal engine*, analogous to `ScheduledWorkflowJob` and OpenConnector
`JobTask`, not a derived field. This is precisely the "what apps SHOULD still
write in PHP" seam ADR-031 preserves (external clocks / scheduled sweeps are not
schema-derivable). The change adds **one** generic engine, not per-app service
code — every virtual app gets scheduling declaratively, with no PHP of its own.

### D-2 — Interval AND cron, both in v1 (CONFIRMED)

A `schedules[]` entry carries exactly one of `interval` (positive integer
seconds) or `cron` (a cron expression); **both are first-class in v1.** The
OpenConnector `job` model is `interval`/`nextRun`-driven, so the two cadence
forms map onto it as follows:

- **`interval`** — written straight onto the reconciled `job` as its `interval`;
  `JobService` advances `lastRun`/`nextRun` by that interval as today.
- **`cron`** — the OC `job` model has no cron field, so the **reconciler
  computes `nextRun` from the cron expression each tick** and writes it onto the
  reconciled `job`. `JobService` then executes any job whose `nextRun` is due
  exactly as it does for interval jobs (it filters on `nextRun`,
  `JobService.php:638-665`). Re-evaluating the cron expression on every sweep
  keeps a cron job's `nextRun` current without any change to `JobService`.

**cron→nextRun evaluator:** prefer an existing cron-expression library already
in the Nextcloud/Symfony dependency tree — **`dragonmantank/cron-expression`**
(the de-facto PHP cron parser, widely transitively vendored) — rather than
hand-rolling cron parsing. If it is not already resolvable in OR's
`composer.json`/`composer.lock`, adding it as a direct dependency is part of the
reconciler tasks (tasks 1/2). The reconciler owns the "cron string → next
`DateTime`" computation; it does not extend the OC `job` schema or `JobService`.

This narrows D-7's "reconciler never touches `nextRun`": the reconciler DOES set
and roll forward the `nextRun` of a **cron** job (to its next scheduled fire
time), but it never rewinds/advances a job's post-execution bookkeeping the way
`JobService` does. The two never fight — `JobService` owns advancement after a
run; the reconciler only ever moves a cron job's `nextRun` forward to the next
cron tick.

### D-3 — Manifest source: on-disk AND virtual, as a foundation capability (CONFIRMED)

The reconciler is a **foundation capability owned by OpenRegister** (see "Why
OpenRegister owns this"): a single generic engine that any manifest app — on
disk or pure-virtual — consumes. It enumerates two manifest sources:

1. **On-disk AppHost manifests** — discovered via the AppHost manifest loader
   (the same enumeration Observability's `ManifestLoader` uses).
2. **Virtual apps** — OR `application` objects in the `openbuild` register,
   whose manifest is resolved the way
   `openbuild/lib/Service/ManifestResolverService.php` resolves it. OR reads
   these as OR objects; it does not call into openbuild code (symmetric to
   `per-app-doriath-application`, where OR owns the registrar and only reads the
   `openbuild`-register `application` objects).

**Decision: cover BOTH in v1** — the reconciler is a generic foundation engine
and the spec's on-disk/virtual scenarios both hold, sharing one
enumeration+validation path. Covering both is what makes this a foundation
capability the whole fleet consumes rather than an openbuild-only feature: a
future consumer (hermiq, openconnector, an on-disk manifest app) declares
`schedules[]` and is scheduled by the same OR engine, no per-app `TimedJob`.

### D-4 — Action allow-list (CONFIRMED — sync-only in v1)

`action` is a closed enum resolved server-side to a vetted `jobClass`. A raw
manifest FQCN is NEVER used as `jobClass` (that would be arbitrary-code
execution as the app owner). **Decision: seed the allow-list with a single
entry, `openconnector:synchronization`** (maps to OpenConnector's synchronization
action class; `arguments.synchronization` names the OC synchronization object).
This is the highest-value action (exactly what a virtual app wants to schedule —
periodic data sync) and reuses OC's execution wholesale. OR-native actions (e.g.
a retention/workflow-run action) can be added to the allow-list later without a
schema change — the allow-list is server-owned data.

### D-5 — Idempotency, matching key, and GC

- **Matching key = `applicationId + scheduleId`** carried on the reconciled
  `job` object (e.g. a `source`/`origin` marker plus the schedule `id`), so the
  reconciler can find "the job for this schedule" deterministically.
- **Upsert**: if a matching `job` exists, update its interval/arguments/enabled
  in place; else create one. Never create a second job for the same key
  (mirrors OC `JobService`'s per-job identity).
- **GC**: on each tick, the reconciler compares reconciled jobs against the
  current set of declared schedule keys per application; a job whose schedule is
  `enabled:false` is disabled (`isEnabled=false`), and a job whose schedule `id`
  is gone from the manifest is disabled or removed. Run history is preserved on
  disable.
- For **interval** jobs the reconciler never touches `lastRun`/`nextRun` — that
  bookkeeping stays owned by `JobService` (`JobService.php:433-460`). For
  **cron** jobs the reconciler sets/rolls forward `nextRun` to the next cron
  fire time (D-2) but still never rewinds a job mid-flight; post-execution
  advancement remains `JobService`'s.

### D-6 — Owner-scoping / impersonation

The reconciled `job`'s `userId` is set to the owner resolved from the
application, reusing the existing impersonation seam
(`openbuild/lib/Service/JobOwnerImpersonator.php:40`; OC's impersonation path
`JobService.php:368-390`). Any author-supplied `runAs`/`owner` in the manifest
is ignored. If no owner can be resolved, no job is created (fail-closed) — a
schedule must never run under an ambiguous or elevated identity.

### D-7 — Reuse of OC JobTask/JobService (ADR-011)

The reconciler does not re-implement execution. It only produces/curates OC
`job` objects; `openconnector/lib/BackgroundJob/JobTask.php` + `JobService::run()`
already: filter `isEnabled=true`, skip future `nextRun`, resolve `jobClass` via
the container, call `$action->run($arguments)`, log, and advance
`lastRun`/`nextRun`. Reconciler and executor are two independent `TimedJob`s
(OR's reconciler + OC's `JobTask`), each idempotent.

## Mixed-spec rationale (ADR-032)

This change is `kind: code` — its centre of mass is the OR reconciler
`TimedJob` + AppHost scheduling engine (PHP). It also requires a **thin,
additive, cross-repo config delta** in nextcloud-vue: add `schedules[]` to
`src/schemas/app-manifest-v2.schema.json` and the matching type in
`src/types/manifest.d.ts`. This is not the ADR-032 `mixed` anti-pattern for
three reasons:

1. **Different repo, different pipeline.** ADR-032's `mixed`-is-rejected rule
   targets a single Hydra cycle whose 200-turn budget is sized for one reviewer
   surface. The nc-vue delta lands in a **separate repo** (nc-vue, `beta`
   branch) via its own pipeline — it never competes with the OR code cycle's
   budget.
2. **The nc-vue delta is a pure declaration, not logic.** It adds one
   JSON-schema property + one TS type. There is no Vue/TS behaviour — nc-vue
   only publishes the schema/type so authors and validators recognise
   `schedules[]`. All scheduling behaviour lives in the OR engine.
3. **Tight coupling to the OR engine.** The schema shape is defined by what the
   OR reconciler consumes; splitting it into an independent cross-repo change
   with its own review overhead buys little when the consuming engine is the
   thing under review.

**Provisional decision: single `code` change in OR + the nc-vue schema/type
edit landed as a config task (task 1).** This mirrors `per-app-doriath-
application`, which kept a single `code` change while touching cross-repo
surfaces.

**Chain-split alternative (RAISED — DQ-a):** if the reviewer prefers strict
ADR-032 hygiene, split into a chain: nc-vue `manifest-schedules-schema-
declaration` (`kind: config`, merges first, publishes `schedules[]`) →
openregister `apphost-schedules-reconciler` (`kind: code`, `depends_on` the
config spec). Cleaner on paper; heavier for a ~10-line additive schema/type
change. Left as the alternative for the user to choose.

## Seed data

**None.** This change introduces no OR register/schema JSON and therefore no
seed objects. The OpenConnector `job` schema already exists; reconciled jobs are
OC `job` objects created at runtime by the reconciler, not seeded.

## Risks / Trade-offs

- **[Security — arbitrary jobClass]** A tenant manifest could try to run
  arbitrary PHP as the app owner. → Closed allow-list; `jobClass` is
  server-resolved, never taken from manifest data (D-4). Non-allow-listed
  actions are rejected + logged.
- **[Cross-tenant identity]** A schedule running as the wrong user. →
  Owner resolved from the application, author `runAs` ignored, fail-closed on
  unresolved owner (D-6).
- **[Duplicate/orphan jobs]** Reconciliation racing or a removed schedule. →
  Deterministic `applicationId+scheduleId` key + GC (D-5).
- **[cron correctness]** A malformed cron expression or an evaluator that drifts.
  → Use a vendored cron-expression library (`dragonmantank/cron-expression`),
  not a hand-rolled parser; an unparseable `cron` is a rejected entry (logged),
  not a silently-dropped one (D-2).
- **[Enumeration cost]** Sweeping every published manifest each tick. → Mirror
  `ScheduledWorkflowJob`'s cadence (60s) and enumerate cheaply (reuse the
  AppHost manifest loader + an `openbuild`-register query); the reconcile is a
  set-diff, and actual execution is OC's separate `JobTask` (300s).

## Migration Plan

1. Land the nc-vue schema/type delta (`schedules[]`) — additive, backward
   compatible; existing manifests without `schedules[]` are unaffected.
2. Land the OR reconciler `TimedJob` + AppHost scheduling engine + allow-list;
   register the job in `appinfo/info.xml <background-jobs>`. Inert on instances
   with no `schedules[]` in any manifest.
3. A virtual app author adds `schedules[]`; on the next reconciler tick a `job`
   is upserted and OC's `JobTask` begins executing it. Rollback = disable the
   reconciler job; reconciled OC `job` objects can be disabled/removed (no
   custody or data migration involved).

## Resolved decisions (confirmed by the user)

- **(DQ-a) Kind/split — CONFIRMED**: single `code` change in OR + the nc-vue
  schema/type edit as a thin config task (Mixed-spec rationale above). The
  ADR-032 chain-split remains the documented alternative, not adopted.
- **(DQ-b) Cadence vocabulary — CONFIRMED**: **both `interval` and `cron` in
  v1**; the reconciler computes cron→`nextRun` via a vendored cron-expression
  library (D-2).
- **(DQ-c) Allow-list breadth — CONFIRMED**: seed with `openconnector:
  synchronization` only; more actions are additive server-owned data later (D-4).
- **(DQ-d) Manifest source coverage — CONFIRMED**: **both on-disk and virtual**,
  framed as an OpenRegister-owned foundation scheduling capability (D-3, "Why
  OpenRegister owns this").
- **(Trigger ownership) — CONFIRMED**: OR owns enumeration+reconciliation via
  its own periodic sweep (symmetric to `per-app-doriath-application`); **no
  openbuild code change in v1**.

## Open Questions

1. **openbuild publish-time hook (future option only)**: OR's periodic sweep is
   sufficient for v1. A future optimisation could add an openbuild publish-time
   trigger so a newly-published virtual app is reconciled immediately rather than
   on the next sweep — not needed now, noted for later.
