## Tasks

- [x] Add `schedules[]` to `src/schemas/app-manifest-v2.schema.json` + the matching type in `src/types/manifest.d.ts` in nextcloud-vue (config; additive: `id`, exactly one of `interval`|`cron`, `action`, `arguments`, `enabled` default true; no execution identity field).
- [x] Create `lib/AppHost/Scheduling/` engine: a `ScheduleManifest`/loader that parses+validates a manifest's `schedules[]` (reject+log entries lacking exactly one of interval/cron, and unparseable `cron`), mirroring `lib/AppHost/Observability/ManifestLoader.php`.
- [x] Add a cron→`nextRun` evaluator using a vendored cron-expression library (prefer `dragonmantank/cron-expression`; add it to OR `composer.json`/`composer.lock` if not already resolvable) — no hand-rolled cron parsing.
- [x] Implement a closed, server-controlled action allow-list mapping `action` types to vetted `jobClass` values (seed: `openconnector:synchronization`); NEVER use a manifest-supplied FQCN as `jobClass`; reject+log non-allow-listed actions.
- [x] Implement the reconciler service: enumerate on-disk AppHost manifests AND virtual apps (OR `application` objects in the `openbuild` register), and for each valid+enabled+allow-listed schedule idempotently UPSERT an OpenConnector `job` object keyed on `applicationId + scheduleId` — writing `interval` for interval schedules and a computed `nextRun` for cron schedules.
- [x] Resolve the reconciled `job`'s `userId` from the owning application via the existing owner-impersonation seam (`JobOwnerImpersonator` / `JobService.php:368-390`); ignore any author-supplied `runAs`/`owner`; skip+log when no owner resolves (fail-closed).
- [x] Implement GC: on each tick disable (`isEnabled=false`) jobs whose schedule is `enabled:false` and disable/remove jobs whose schedule `id` is gone from the manifest; leave interval jobs' `lastRun`/`nextRun` to `JobService` (reconciler only rolls a cron job's `nextRun` forward, never rewinds a job mid-flight).
- [x] Add `lib/BackgroundJob/ScheduleReconcilerJob.php` (`TimedJob`, mirror `ScheduledWorkflowJob.php:70,84-90`) that drives the reconciler, and register it in `appinfo/info.xml <background-jobs>`.
- [x] Confirm execution is reused, not reimplemented: reconciled `job` objects run via existing `openconnector/lib/BackgroundJob/JobTask.php` → `JobService::run()`; OR writes no execution/logging code (cron→`nextRun` computation is the only `nextRun` OR writes).
- [x] Unit tests: manifest parse/validate (interval vs cron vs both/neither/unparseable-cron); cron→`nextRun` computation; idempotent upsert (create → no-op → in-place update); GC (disable + orphan removal); allow-list (vetted mapping + non-allow-listed rejection); owner resolved from app (author `runAs` ignored, unresolved → skip).
- [x] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); add `@spec openspec/changes/apphost-manifest-schedules/specs/apphost-scheduling/spec.md` tags to all new/changed methods.
- [x] Run `openspec validate apphost-manifest-schedules --strict` and fix until clean.

## Acceptance criteria

- A virtual app (no PHP on disk) that declares `schedules[]` in its manifest has a corresponding enabled OpenConnector `job` upserted and executed by the existing `JobTask`/`JobService` path.
- On-disk manifest apps declaring `schedules[]` are reconciled identically to virtual apps.
- Exactly one `job` exists per `applicationId + scheduleId`; re-reconciliation is a no-op; a field change updates that job in place (no duplicate).
- Setting `enabled:false` disables the job; removing a schedule disables/removes its job (GC); run history is preserved on disable.
- A non-allow-listed `action` (including a raw FQCN) creates no job and is logged; `jobClass` is only ever a server-vetted value.
- The reconciled job runs as the application owner (author-supplied identity is ignored); an unresolved owner produces no job.
- No new OR schema/register/seed JSON; the OpenConnector `job` schema and execution path are unchanged.
- Examples/tests use only placeholders (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_TOKEN_HERE`).

## Quality checklist

- ADR-031: manifest `schedules[]` is declarative (temporal peer of manifest `observability`/`credentials`); the reconciler `TimedJob` is a justified imperative exception — a foundation temporal engine OR owns because NC core has no cron API beyond raw `TimedJob`, analogous to `ScheduledWorkflowJob`, not a derived field.
- ADR-032: `kind: code`; the nc-vue schema/type delta is a thin additive cross-repo config declaration (Mixed-spec rationale in design.md); chain-split is the RAISED alternative.
- ADR-011: reuses OpenConnector `JobTask`/`JobService` execution + the owner-impersonation seam + the AppHost manifest loader — no reimplementation.
- ADR-005: allow-listed actions only (no tenant FQCN → no code exec), owner-scoped/fail-closed identity, no secret/PII in logs.
- No seed data (no new OR schema); reconciled jobs are runtime-created OC `job` objects.
- Confirmed decisions (single code spec, interval+cron both, sync-only allow-list, on-disk+virtual foundation engine, OR-owned sweep no openbuild change) recorded in design.md Resolved decisions; only the optional openbuild publish-time trigger remains an Open Question.
