---
kind: code
---

## Why

A pure-virtual OpenBuild/manifest-driven app — one whose manifest is stored as
an OpenRegister `application` object with **no PHP on disk** — cannot schedule
any recurring work today. Classic apps schedule via
`OCP\BackgroundJob\TimedJob` + `appinfo/info.xml <background-jobs>` (e.g.
`openregister/appinfo/info.xml:94-121`, `openconnector/appinfo/info.xml:50-54`,
`openbuild/appinfo/info.xml:101-104`), but a virtual app ships no `jobClass` and
the manifest v2 schema has **no** schedules/jobs/cron key at all. Scheduling is
the missing temporal peer of the already-shipped manifest-declared
`observability` and `credentials` capabilities: an app can already declare
metrics and brokered credentials in its manifest and have a generic OR AppHost
engine consume them, but it cannot declare "run this every N seconds".

Nextcloud core exposes **no cron API beyond the raw `OCP\BackgroundJob\TimedJob`
PHP primitive** (driven by `cron.php`; no REST/OCS/declarative cron in core), so
a virtual app has no scheduling path at all, and every classic app hand-rolls
the same data-driven loop (OpenConnector's `JobTask`, OR's own
`ScheduledWorkflowJob`). A generic, manifest/data-driven scheduling engine
therefore belongs in **OpenRegister — the foundation** — so openbuild, hermiq,
openconnector and any manifest app consume **one** OR scheduling engine instead
of each writing its own `TimedJob`. This change is a foundation capability that
generalises those existing per-app duplications.

## What Changes

- **Manifest v2 gains a top-level `schedules[]` key.** Each entry declares a
  stable `id`, exactly one of `interval` (seconds) OR `cron` (expression) — both
  first-class in v1 — an **allow-listed generic `action` type** (e.g.
  `openconnector:synchronization` with a `synchronization` ref — **never** a raw
  PHP FQCN from tenant manifest data), an `arguments` object, and `enabled`
  (bool, default `true`). Owner is **resolved from the application owner, not
  author-supplied**. (nc-vue schema + type delta — see Design "Mixed-spec
  rationale".)
- **A single new generic OR AppHost reconciler `TimedJob`.** Mirrors
  `lib/BackgroundJob/ScheduledWorkflowJob.php:70,84-90` (a 60s `TimedJob` that
  enumerates enabled records and drives a generic engine). Each tick it
  enumerates published apps' manifests — both **on-disk** AppHost manifests
  (served by OR directly) and **virtual** apps (OR `application` objects in the
  `openbuild` register, manifest resolved by
  `openbuild/lib/Service/ManifestResolverService.php`) — reads each
  `schedules[]`, and **idempotently UPSERTS** a corresponding OpenConnector
  `job` OR object keyed on `applicationId + scheduleId`. For a `cron` schedule
  the reconciler computes `nextRun` from the cron expression (via a vendored
  cron-expression library, e.g. `dragonmantank/cron-expression`) and writes it
  onto the job; an `interval` schedule maps straight to the job's `interval`.
- **Execution reuses the proven OpenConnector data-driven scheduler.** The
  reconciled `job` objects are executed by the existing
  `openconnector/lib/Cron/JobTask.php:63,91` → `JobService::run()`
  (`openconnector/lib/Service/JobService.php:638-665`) path, which advances
  `lastRun`/`nextRun` and logs. No new execution/logging engine is written.
- **Owner-scoping + allow-list guardrails.** The reconciler sets the job's
  `userId` to the resolved application owner (owner impersonation already solved:
  `openbuild/lib/Service/JobOwnerImpersonator.php:40`,
  `JobService.php:368-390`), and rejects/skips-and-logs any schedule whose
  `action` is not on a closed allow-list of generic action types.
- **Garbage collection.** A schedule that is removed or `enabled:false` in a
  manifest disables/removes its reconciled `job` object on the next tick.

## Capabilities

### New Capabilities

- `apphost-scheduling`: declarative scheduled tasks for on-disk and virtual
  manifest apps — the manifest `schedules[]` vocabulary, a generic OR AppHost
  reconciler that idempotently upserts OpenConnector `job` objects from those
  declarations, an allow-listed action model, owner-scoped execution identity,
  and GC of reconciled jobs when a schedule is disabled/removed.

### Modified Capabilities

<!-- none — the OpenConnector `job` schema and JobTask/JobService execution
     path are reused unchanged; no existing OR spec's requirements change. -->

## Impact

- **OpenRegister (this repo):**
  - New `lib/AppHost/Scheduling/` engine (reconciler service that enumerates
    manifests, validates the allow-list, resolves the owner, and upserts/GCs OC
    `job` objects) alongside the existing `lib/AppHost/Observability/` engines.
  - New `lib/BackgroundJob/ScheduleReconcilerJob.php` `TimedJob` (mirrors
    `ScheduledWorkflowJob`), registered in `appinfo/info.xml <background-jobs>`.
  - Manifest enumeration reuses the AppHost manifest loader (on-disk) and, for
    virtual apps, resolves OR `application` objects in the `openbuild` register.
- **nextcloud-vue (cross-repo, thin config delta):** add `schedules[]` to
  `src/schemas/app-manifest-v2.schema.json` and the matching type in
  `src/types/manifest.d.ts`. A pure additive JSON-schema property + type; no Vue
  logic. See Design "Mixed-spec rationale" (and the chain-split alternative).
- **OpenConnector:** none — the `job` schema
  (`openconnector/lib/Settings/openconnector_register.json`) and the
  `JobTask`/`JobService` execution path are reused as-is.
- **openbuild:** for virtual apps, OR owns the reconciler and enumerates the
  `openbuild`-register `application` manifests — symmetric to the just-merged
  `per-app-doriath-application` split (OR owns the registrar/engine; openbuild
  only owns publishing the manifest). **No openbuild code change in v1**; an
  optional publish-time trigger is a documented future option (see Design Open
  Questions).
- **No OR schema/seed changes.** No new OR register/schema JSON is introduced —
  the OC `job` schema already exists; reconciled jobs are OC `job` objects.
